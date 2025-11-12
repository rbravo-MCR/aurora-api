<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public string $appName = 'Aurora API') {}

    public function build()
    {
        return $this->subject("Tu código de acceso ({$this->appName})")
            ->markdown('emails.login-otp')
            ->with([
                'code' => $this->code,
                'app'  => $this->appName,
            ]);
    }
}
