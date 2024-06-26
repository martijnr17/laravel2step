<?php

namespace jeremykenedy\laravel2step\App\Traits;

use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use jeremykenedy\laravel2step\App\Models\TwoStepAuth;
use jeremykenedy\laravel2step\App\Notifications\SendVerificationCodeEmail;

trait Laravel2StepTrait
{
    /**
     * Check if the user is authorized.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function twoStepVerification($request)
    {
        $user = Auth::User();

        if ($user) {
            $twoStepAuthStatus = $this->checkTwoStepAuthStatus($user->id);

            if ($twoStepAuthStatus->authStatus !== true) {
                return false;
            } else {
                if ($this->checkTimeSinceVerified($twoStepAuthStatus)) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * Check time since user was last verified and take apprpriate action.
     *
     * @param collection $twoStepAuth
     *
     * @return bool
     */
    private function checkTimeSinceVerified($twoStepAuth)
    {
        $expireMinutes = config('laravel2step.laravel2stepVerifiedLifetimeMinutes');
        $now = Carbon::now();
        $expire = Carbon::parse($twoStepAuth->authDate)->addMinutes($expireMinutes);
        $expired = $now->gt($expire);

        if ($expired) {
            $this->resetAuthStatus($twoStepAuth);

            return true;
        }else{
		$this->resetActivationCountdown($twoStepAuth);
	}

        return false;
    }

    /**
     * Reset TwoStepAuth collection item and code.
     *
     * @param collection $twoStepAuth
     *
     * @return collection
     */
    private function resetAuthStatus($twoStepAuth)
    {
        $twoStepAuth->authCode = $this->generateCode();
        $twoStepAuth->authCount = 0;
        $twoStepAuth->authStatus = 0;
        $twoStepAuth->authDate = null;
        $twoStepAuth->requestDate = null;

        $twoStepAuth->save();

        return $twoStepAuth;
    }

    /**
     * Generate Authorization Code.
     *
     * @param int    $length
     * @param string $prefix
     * @param string $suffix
     *
     * @return string
     */
    private function generateCode(int $length = 4, string $prefix = '', string $suffix = '')
    {
        for ($i = 0; $i < $length; $i++) {
            $prefix .= random_int(0, 9);
        }

        return $prefix.$suffix;
    }

    /**
     * Create/retreive 2step verification object.
     *
     * @param int $userId
     *
     * @return collection
     */
    private function checkTwoStepAuthStatus(int $userId)
    {
        $twoStepAuth = TwoStepAuth::firstOrCreate(
            [
                'userId' => $userId,
            ],
            [
                'userId'    => $userId,
                'authCode'  => $this->generateCode(),
                'authCount' => 0,
            ]
        );

        return $twoStepAuth;
    }

    /**
     * Retreive the Verification Status.
     *
     * @param int $userId
     *
     * @return collection || void
     */
    protected function getTwoStepAuthStatus(int $userId)
    {
        return TwoStepAuth::where('userId', $userId)->firstOrFail();
    }

    /**
     * Format verification exceeded timings with Carbon.
     *
     * @param string $time
     *
     * @return collection
     */
    protected function exceededTimeParser($time)
    {
        $tomorrow = Carbon::parse($time)->addMinutes(config('laravel2step.laravel2stepExceededCountdownMinutes'))->format('l, F jS Y h:i:sa');
        $remaining = $time->addMinutes(config('laravel2step.laravel2stepExceededCountdownMinutes'))->diffForHumans(null, true);

        $data = [
            'tomorrow'  => $tomorrow,
            'remaining' => $remaining,
        ];

        return collect($data);
    }

    /**
     * Check if time since account lock has expired and return true if account verification can be reset.
     *
     * @param datetime $time
     *
     * @return bool
     */
    protected function checkExceededTime($time)
    {
        $now = Carbon::now();
        $expire = Carbon::parse($time)->addMinutes(config('laravel2step.laravel2stepExceededCountdownMinutes'));
        $expired = $now->gt($expire);

        if ($expired) {
            return true;
        }

        return false;
    }

    /**
     * Method to reset code and count.
     *
     * @param collection $twoStepEntry
     *
     * @return collection
     */
    protected function resetExceededTime($twoStepEntry)
    {
        $twoStepEntry->authCount = 0;
        $twoStepEntry->authCode = $this->generateCode();
        $twoStepEntry->save();

        return $twoStepEntry;
    }

    /**
     * Successful activation actions.
     *
     * @param collection $twoStepAuth
     *
     * @return void
     */
    protected function resetActivationCountdown($twoStepAuth)
    {
        $twoStepAuth->authCode = $this->generateCode();
        $twoStepAuth->authCount = 0;
        $twoStepAuth->authStatus = 1;
        $twoStepAuth->authDate = Carbon::now();
        $twoStepAuth->requestDate = null;

        $twoStepAuth->save();
    }

    /**
     * Send verification code via notify.
     *
     * @param array  $user
     * @param string $deliveryMethod (nullable)
     * @param string $code
     *
     * @return void
     */
	//changes
       protected function sendVerificationCodeNotification($twoStepAuth, $deliveryMethod = null)
    {
        $user = Auth::User();
		if(!isset($user->mobiel)){
			$user->notify(new SendVerificationCodeEmail($user, $twoStepAuth->authCode));
		}else{
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,"https://api.spryngsms.com/api/send.php");
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,"USERNAME=".config('laravel2step.laravel2stepOtpAccount')."&PASSWORD=".config('laravel2step.laravel2stepOtpAuthToken')."&SENDER=".config('laravel2step.laravel2stepOtpFrom')."&ROUTE=".config('laravel2step.laravel2stepOtpRoute')."&DESTINATION=".$user->mobiel."&BODY=Code:".$twoStepAuth->authCode."");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$response = curl_exec($ch);
			curl_close ($ch);
		}
        $twoStepAuth->requestDate = Carbon::now();

        $twoStepAuth->save();
    }
}
