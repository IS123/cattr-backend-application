<?php

namespace App\Http\Controllers;

use App\Exceptions\Entities\AuthorizationCaptchaException;
use App\Exceptions\Entities\AuthorizationException;
use App\Helpers\Recaptcha\Recaptcha;
use App\User;
use App\Helpers\CatHelper;
use GuzzleHttp\Client;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\{
    Request, Response, JsonResponse
};
use Illuminate\Support\Facades\{
    Auth, DB, Hash, Password
};
use Illuminate\Support\Str;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpKernel\Exception\HttpException;


/**
 * Class AuthController
 *
 * @package App\Http\Controllers
 */
class AuthController extends BaseController
{
    /**
     * @apiDefine UnauthorizedError
     *
     * @apiErrorExample {json} Access Error Example
     * {
     *    "error":      "Access denied",
     *    "reason":     "not logged in",
     *    "error_code": "ERR_NO_AUTH"
     * }
     *
     * @apiErrorExample {json} Access Error Example
     * {
     *    "error": "Unauthorized"
     * }
     *
     * @apiError (Error 403) {String} error         Error name
     * @apiError (Error 403) {String} reason        Error description
     * @apiError (Error 403) {String} error_code    Error code
     */

    /**
     * @apiDefine AuthAnswer
     *
     * @apiSuccess {String}     access_token  Token
     * @apiSuccess {String}     token_type    Token Type
     * @apiSuccess {String}     expires_in    Token TTL in seconds
     * @apiSuccess {Array}      user          User Entity
     *
     * @apiSuccessExample {json} Answer Example
     *  {
     *      {
     *        "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciO...",
     *        "token_type": "bearer",
     *         "expires_in": 3600,
     *         "user": {
     *           "id": 42,
     *           "full_name": "Captain",
     *           "email": "johndoe@example.com",
     *           "url": "",
     *           "company_id": 41,
     *           "payroll_access": 1,
     *           "billing_access": 1,
     *           "avatar": "",
     *           "screenshots_active": 1,
     *           "manual_time": 0,
     *           "permanent_tasks": 0,
     *           "computer_time_popup": 300,
     *           "poor_time_popup": "",
     *           "blur_screenshots": 0,
     *           "web_and_app_monitoring": 1,
     *           "webcam_shots": 0,
     *           "screenshots_interval": 9,
     *           "active": "active",
     *           "deleted_at": null,
     *           "created_at": "2018-09-25 06:15:08",
     *           "updated_at": "2018-09-25 06:15:08",
     *           "timezone": null
     *         }
     *      }
     *  }
     */

    /**
     * @var Recaptcha
     */
    protected $recaptcha;

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(Recaptcha $recaptcha)
    {
        $this->recaptcha = $recaptcha;

        $this->middleware('auth:api', [
            'except' => ['check', 'login', 'refresh', 'sendReset', 'getReset', 'reset']
        ]);
    }

    /**
     * @return JsonResponse
     * @api {any} /api/auth/ping Ping
     * @apiDescription Get API status
     *
     * @apiVersion 0.1.0
     * @apiName Ping
     * @apiGroup Auth
     *
     * @apiSuccess {Integer}   status API HTTP-code status
     * @apiSuccess {Boolean}   error  Error
     * @apiSuccess {String}    cat    Sample Cat
     *
     * @apiSuccessExample {json} Answer Example
     *  {
     *      "status": 200,
     *      "error":  false,
     *      "cat":    '(=ㅇ༝ㅇ=)'
     *  }
     *
     */
    public function ping(): JsonResponse
    {
        $helper = new CatHelper();

        return response()->json([
            'status' => 200,
            'error' => false,
            'cat' => $helper->getCat(),
        ]);
    }

    /**
     * @return JsonResponse
     * @api {any} /api/auth/check Check
     * @apiDescription Check API status
     *
     * @apiVersion 0.1.0
     * @apiName Check
     * @apiGroup Auth
     *
     * @apiSuccess {Integer}   code API HTTP-code status
     * @apiSuccess {Boolean}   amazingtime
     *
     * @apiSuccessExample {json} Answer Example
     *  {
     *      "code": 200,
     *      "amazingtime": true,
     *  }
     *
     */
    public function check(): JsonResponse
    {
        return response()->json([
            'code' => 200,
            'amazingtime' => true,
        ]);
    }

    protected function invalidateToken(Request $request)
    {
        $auth = explode(' ', $request->header('Authorization'));
        if (!empty($auth) && count($auth) > 1 && $auth[0] == 'bearer') {
            $token = $auth[1];
            $user = auth()->user();
            if (isset($user)) {
                DB::table('tokens')->where([
                    ['user_id', auth()->user()->id],
                    ['token', $token],
                ])->delete();
            }
        }
    }

    /**
     * @param null|string $except
     */
    protected function invalidateAllTokens($except = null)
    {
        $conditions = [
            ['user_id', auth()->user()->id],
        ];

        if (isset($except)) {
            $conditions[] = ['token', '!=', $except];
        }

        DB::table('tokens')->where($conditions)->delete();
    }

    protected function setToken(string $token)
    {
        $expires_timestamp = time() + 60 * auth()->factory()->getTTL();

        DB::table('tokens')->insert([
            'user_id' => auth()->user()->id,
            'token' => $token,
            'expires_at' => date('Y-m-d H:i:s', $expires_timestamp),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     * @api {post} /api/auth/login Login
     * @apiDescription Get user JWT
     *
     *
     * @apiVersion 0.1.0
     * @apiName Login
     * @apiGroup Auth
     *
     * @apiParam {String}   login       User login
     * @apiParam {String}   password    User password
     * @apiParam {String}   recaptcha   Recaptcha token
     *
     * @apiSuccess {String}     access_token  Token
     * @apiSuccess {String}     token_type    Token Type
     * @apiSuccess {String}     expires_in    Token TTL in seconds
     * @apiSuccess {Array}      user          User Entity
     *
     * @apiError (Error 401) {String} Error Error
     *
     * @apiParamExample {json} Request Example
     *  {
     *      "login":      "johndoe@example.com",
     *      "password":   "amazingpassword",
     *      "recaptcha":  "03AOLTBLR5UtIoenazYWjaZ4AFZiv1OWegWV..."
     *  }
     *
     * @apiUse AuthAnswer
     * @apiUse UnauthorizedError
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = request([
            'login',
            'password',
            'recaptcha'
        ]);

        $data = [
            'email' => $credentials['login'] ?? null,
            'password' => $credentials['password'] ?? null,
        ];

        if (!$data['email']) {
            throw new AuthorizationException(AuthorizationException::ERROR_TYPE_UNAUTHORIZED);
        }

        if (!$this->recaptcha->getRateLimiter()->allowedIp()) {
            $this->recaptcha->getRateLimiter()->incIp();
            throw new AuthorizationException(AuthorizationException::ERROR_TYPE_BANNED);
        }

        if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($data['email']) && !$this->recaptcha->testCaptcha($credentials['recaptcha'] ?? '')) {
            $this->recaptcha->inc($data['email']);
            throw new AuthorizationCaptchaException();
        }

        /** @var string $token */
        if (!$token = auth()->attempt($data)) {
            $this->recaptcha->inc($data['email']);
            if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($data['email'])) {
                throw new AuthorizationCaptchaException();
            } else {
                throw new AuthorizationException(AuthorizationException::ERROR_TYPE_UNAUTHORIZED);
            }
        }

        $user = auth()->user();

        if ($user && !$user->active) {
            $this->recaptcha->inc($data['email']);
            throw new AuthorizationException(AuthorizationException::ERROR_TYPE_USER_DISABLED);
        }

        $this->recaptcha->forgetCurrentIp($data['email']);

        $this->setToken($token);

        return $this->respondWithToken($token);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @param Request $request
     * @return JsonResponse
     * @api {any} /api/auth/logout Logout
     * @apiDescription Invalidate JWT
     * @apiVersion 0.1.0
     * @apiName Logout
     * @apiGroup Auth
     *
     * @apiSuccess {String}    message    Action result message
     *
     * @apiSuccessExample {json} Answer Example
     *  {
     *      "message": "Successfully logged out"
     *  }
     *
     * @apiUse UnauthorizedError
     *
     */
    public function logout(Request $request): JsonResponse
    {
        $this->invalidateToken($request);
        auth()->logout();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Log the user out (Invalidate all tokens).
     *
     * @param Request $request
     * @return JsonResponse
     * @api {any} /api/auth/logout Logout
     * @apiDescription Invalidate JWT
     * @apiVersion 0.1.0
     * @apiName Logout
     * @apiGroup Auth
     *
     * @apiParamExample {json} Request Example
     *  {
     *      "token": "eyJ0eXAiOiJKV1QiLCJhbGciO..."
     *  }
     *
     * @apiSuccess {String}    message    Action result message
     *
     * @apiSuccessExample {json} Answer Example
     *  {
     *      "message": "Successfully ended all sessions"
     *  }
     *
     * @apiUse UnauthorizedError
     *
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $token = $request->json()->get('token');
        if (isset($token)) {
            $this->invalidateAllTokens($token);
        } else {
            $this->invalidateAllTokens();
            auth()->logout();
        }

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * @api {get} /api/auth/me Me
     * @apiDescription Get authenticated User Entity
     *
     * @apiVersion 0.1.0
     * @apiName Me
     * @apiGroup Auth
     *
     * @apiSuccess {String}     access_token  Token
     * @apiSuccess {String}     token_type    Token Type
     * @apiSuccess {String}     expires_in    Token TTL in seconds
     * @apiSuccess {Array}      user          User Entity
     *
     * @apiUse UnauthorizedError
     *
     * @apiSuccessExample {json} Answer Example
     * {
     *   "id": 1,
     *   "full_name": "Admin",
     *   "email": "admin@example.com",
     *   "url": "",
     *   "company_id": 1,
     *   "payroll_access": 1,
     *   "billing_access": 1,
     *   "avatar": "",
     *   "screenshots_active": 1,
     *   "manual_time": 0,
     *   "permanent_tasks": 0,
     *   "computer_time_popup": 300,
     *   "poor_time_popup": "",
     *   "blur_screenshots": 0,
     *   "web_and_app_monitoring": 1,
     *   "webcam_shots": 0,
     *   "screenshots_interval": 9,
     *   "active": "active",
     *   "deleted_at": null,
     *   "created_at": "2018-09-25 06:15:08",
     *   "updated_at": "2018-09-25 06:15:08",
     *   "timezone": null
     * }
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        return response()->json(auth()->user());
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @api {post} /api/auth/refresh Refresh
     * @apiDescription Refresh JWT
     *
     * @apiVersion 0.1.0
     * @apiName Refresh
     * @apiGroup Auth
     *
     * @apiUse UnauthorizedError
     *
     * @apiUse AuthAnswer
     */
    public function refresh(Request $request): JsonResponse
    {
        $this->invalidateToken($request);
        $token = auth()->refresh();
        $this->setToken($token);
        return $this->respondWithToken($token);
    }

    /**
     * Get the token array structure.
     *
     * @param string $token
     *
     * @return JsonResponse
     */
    protected function respondWithToken($token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }

    /**
     * Get the broker to be used during password reset.
     *
     * @return PasswordBroker
     */
    protected function broker()
    {
        return Password::broker();
    }

    /**
     * Get the guard to be used during password reset.
     *
     * @return StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard();
    }

    /**
     * @return Response|JsonResponse
     * @api {post} /api/auth/send-reset Send reset e-mail
     * @apiDescription Get user JWT
     *
     *
     * @apiVersion 0.1.0
     * @apiName Send reset
     * @apiGroup Auth
     *
     * @apiParam {String}   login       User login
     * @apiParam {String}   recaptcha   Recaptcha token
     *
     * @apiError (Error 401) {String} Error Error
     *
     * @apiParamExample {json} Request Example
     *  {
     *      "login":      "johndoe@example.com",
     *      "recaptcha":  "03AOLTBLR5UtIoenazYWjaZ4AFZiv1OWegWV..."
     *  }
     *
     * @apiUse AuthAnswer
     * @apiUse UnauthorizedError
     *
     */
    public function sendReset()
    {
        $email = request('login', '');
        $captcha = request('recaptcha', '');

        if (!$this->recaptcha->getRateLimiter()->allowedIp()) {
            $this->recaptcha->getRateLimiter()->incIp();
            return response()->json(['error' => 'Enhance Your Calm'], 420);
        }

        if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($email) && !$this->recaptcha->testCaptcha($captcha)) {
            $this->recaptcha->inc($email);
            return response()->json(['error' => 'User with such email isn’t found or captcha required!', 'site_key' => AuthorizationCaptchaException::getSiteKey()], 429);
        }

        $user = User::query()->where(['email' => $email])->first();
        if (!isset($user)) {
            $this->recaptcha->inc($email);

            if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($email)) {
                $this->recaptcha->inc($email);
                return response()->json(['error' => 'User with such email isn’t found or captcha required!', 'site_key' => AuthorizationCaptchaException::getSiteKey()], 429);
            }

            return response()->json([
                'error' => 'User with such email isn’t found',
            ], 404);
        }

        $this->recaptcha->forgetCurrentIp($email);
        $credentials = ['email' => $email];
        $this->broker()->sendResetLink($credentials);

        return response()->json([
            'message' => 'Link for restore password has been sent to your email.',
        ], 200);
    }


    /**
     * @return JsonResponse
     * @throws AuthorizationException
     * @api {post} /api/auth/reset Reset
     * @apiDescription Get user JWT
     *
     *
     * @apiVersion 0.1.0
     * @apiName Reset
     * @apiGroup Auth
     *
     * @apiParam {String}   login       User login
     * @apiParam {String}   token       Password reset token
     * @apiParam {String}   password    User password
     * @apiParam {String}   recaptcha   Recaptcha token
     *
     * @apiSuccess {String}     access_token  Token
     * @apiSuccess {String}     token_type    Token Type
     * @apiSuccess {String}     expires_in    Token TTL in seconds
     * @apiSuccess {Array}      user          User Entity
     *
     * @apiError (Error 401) {String} Error Error
     *
     * @apiParamExample {json} Request Example
     *  {
     *      "login":      "johndoe@example.com",
     *      "token":      "16184cf3b2510464a53c0e573c75740540fe...",
     *      "password":   "amazingpassword",
     *      "recaptcha":  "03AOLTBLR5UtIoenazYWjaZ4AFZiv1OWegWV..."
     *  }
     *
     * @apiUse AuthAnswer
     * @apiUse UnauthorizedError
     *
     */
    public function reset()
    {
        $data = request(['token', 'password']);
        $data['email'] = request('login');
        $data['password_confirmation'] = $data['password'];

        if (!$this->recaptcha->getRateLimiter()->allowedIp()) {
            $this->recaptcha->getRateLimiter()->incIp();
            throw new AuthorizationException(AuthorizationException::ERROR_TYPE_BANNED);
        }

        if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($data['email']) && !$this->recaptcha->testCaptcha(request('recaptcha', ''))) {
            $this->recaptcha->inc($data['email']);
            throw new AuthorizationCaptchaException();
        }

        $response = $this->broker()->reset(
            $data,
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
                event(new PasswordReset($user));
                $this->guard()->login($user);
            }
        );

        if ($response !== Password::PASSWORD_RESET) {
            $this->recaptcha->inc($data['email']);
            if (!$this->recaptcha->allowedWithoutCaptchaCurrentIp($data['email'])) {
                throw new AuthorizationCaptchaException();
            }
            throw new AuthorizationException(AuthorizationException::ERROR_TYPE_UNAUTHORIZED);
        }

        $this->recaptcha->forgetCurrentIp($data['email']);
        $token = auth()->refresh();
        $this->setToken($token);

        return $this->respondWithToken($token);
    }
}
