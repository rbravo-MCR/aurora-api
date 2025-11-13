<?php

namespace App\Http\Controllers;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;


class AuthController extends Controller
{
    // Paso 1: email+password -> genera y envía OTP por email
    public function login(Request $r)
    {
        $data = $r->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas.']);
        }

        // Throttling básico: máx 5 OTP sin usar en la última hora
        $recent = DB::table('email_otps')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('created_at', '>', now()->subHour())
            ->count();
        if ($recent >= 5) {
            return response()->json(['message' => 'Demasiados intentos, espera unos minutos.'], 429);
        }

        if(!$user->is_active){
            return response()->json([            
                'message' => 'Usuario no activo.',
                'errors' => ['email' => 'Usuario no activo.']
                ],403);
            };
        // Código de 6 dígitos
        $code = (string)random_int(100000, 999999);

        DB::table('email_otps')->insert([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make($code),
            'purpose'    => 'login',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Siempre logea el OTP en local (para pruebas)
        \Log::info('OTP para '.$user->email.': '.$code);

        // Enviar correo — que nunca provoque 500 en local
        try {
            Mail::to($user->email)->send(new LoginOtpMail($code, config('app.name')));
        } catch (\Throwable $e) {
            \Log::error('Error enviando OTP: '.$e->getMessage());
            // No hacemos throw para no romper el flujo en local
        }
        $payload = ['message' => 'código enviado a tu email'];
        if(app()->islocal()){
            $payload['debug_otp']=$code;
        }
        return response()->json(['message' => 'Código enviado a tu email.'], 200);
    }

    // Paso 2: email+OTP -> emite token Sanctum
    public function verify(Request $r)
    {
        $data = $r->validate([
            'email' => ['required','email'],
            'code'  => ['required','digits:6'],
            'device_name' => ['nullable','string']
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => 'Usuario no encontrado.']);
        }

        $otps = DB::table('email_otps')
            ->where('user_id', $user->id)
            ->where('purpose', 'login')
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())   // solo vigentes
            ->orderByDesc('id')
            ->limit(5)
            ->get();

            $match = null;
            foreach ($otps as $o) {
                if (\Illuminate\Support\Facades\Hash::check($data['code'], $o->code_hash)) {
                    $match = $o; break;
                }
            }

            if (!$match) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'code' => 'Código inválido o expirado.',
                ]);
            }

        // usa $match en lugar de $otp:
        DB::table('email_otps')->where('id', $match->id)->update(['used_at' => now()]);
        DB::table('email_otps')
        ->where('user_id', $user->id)
        ->where('purpose','login')
        ->whereNull('used_at')
        ->where('id','<>',$match->id)
        ->delete();

            
        $name = $data['device_name'] ?? ('api-'.Str::random(6));
        $token = $user->createToken($name)->plainTextToken;
    
        session()->put('usuario', $user->name);
        session()->put('usuario_id', $user->id);


        return response()->json([
            'usuario'      => $user->name,
            'usuario_id'   => $user->id,
            'token_type'   => 'Bearer',
            'access_token' => $token,
        ], 200);
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();
        return response()->noContent();
    }


    // para el registro de usuarios
    public function register(Request $r)
    {
        // Validación de entrada
        $data = $r->validate([
            'name'                  => ['required','string','max:255'],
            'email'                 => ['required','email','max:255','unique:users,email'],
            'password'              => ['required','string','min:8','confirmed'], // requiere password_confirmation
        ]);

        // Normaliza el email
        $data['email'] = mb_strtolower($data['email']);

        // Crea el usuario
        $user = \App\Models\User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
        ]);

        // (Opcional) Tira cualquier OTP pendiente por seguridad
        \Illuminate\Support\Facades\DB::table('email_otps')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        // Genera OTP de 6 dígitos (reusamos el propósito 'login' para que funcione /auth/verify tal cual)
        $code = (string) random_int(100000, 999999);

        \Illuminate\Support\Facades\DB::table('email_otps')->insert([
            'user_id'    => $user->id,
            'code_hash'  => \Illuminate\Support\Facades\Hash::make($code),
            'purpose'    => 'login',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log del OTP en local para pruebas
        \Log::info('OTP para '.$user->email.': '.$code);

        // Envía mail (no debe romper el flujo si falla en local)
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)
                ->send(new \App\Mail\LoginOtpMail($code, config('app.name')));
        } catch (\Throwable $e) {
            \Log::error('Error enviando OTP (registro): '.$e->getMessage());
        }

        // Respuesta
        $payload = ['message' => 'Usuario creado. Código enviado a tu email.'];
        if (app()->isLocal()) {
            // Útil en dev para no rascar logs
            $payload['debug_otp'] = $code;
        }

        return response()->json($payload, 201);
    }

}
