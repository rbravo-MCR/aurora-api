<?php

namespace App\Http\Controllers;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    // Paso 1: login con email+password → genera y envía OTP
    public function login(Request $r) {
        $data = $r->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas.']);
        }

        // throttling básico por usuario (5 por hora)
        $recent = DB::table('email_otps')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('created_at', '>', now()->subHour())
            ->count();
        if ($recent >= 5) {
            return response()->json(['message' => 'Demasiados intentos, espera unos minutos.'], 429);
        }

        // generar OTP de 6 dígitos
        $code = (string)random_int(100000, 999999);
        DB::table('email_otps')->insert([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make($code),
            'purpose'    => 'login',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // enviar correo
        Mail::to($user->email)->send(new LoginOtpMail($code, config('app.name')));

        // Por seguridad NO regresamos el código; solo confirmamos envío
        return response()->json(['message' => 'Código enviado a tu email.'], 200);
    }

    // Paso 2: verificar OTP → emitir token Sanctum
    public function verify(Request $r) {
        $data = $r->validate([
            'email' => ['required','email'],
            'code'  => ['required','digits:6'],
            'device_name' => ['nullable','string'] // para identificar el token
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => 'Usuario no encontrado.']);
        }

        $otp = DB::table('email_otps')
            ->where('user_id', $user->id)
            ->where('purpose', 'login')
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp || CarbonImmutable::parse($otp->expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'Código inválido o expirado.']);
        }

        if (!Hash::check($data['code'], $otp->code_hash)) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        // marcar como usado y limpiar otros viejos
        DB::table('email_otps')->where('id', $otp->id)->update(['used_at' => now()]);
        DB::table('email_otps')
            ->where('user_id', $user->id)
            ->where('purpose','login')
            ->whereNull('used_at')
            ->where('id','<>',$otp->id)
            ->delete();

        // emitir token
        $name = $data['device_name'] ?? ('api-'.Str::random(6));
        $token = $user->createToken($name)->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token
        ], 200);
    }

    // Cerrar sesión (revocar token actual)
    public function logout(Request $r) {
        $r->user()->currentAccessToken()->delete();
        return response()->noContent();
    }
}
