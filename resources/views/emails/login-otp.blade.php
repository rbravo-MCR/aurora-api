@component('mail::message')
# Código de acceso

Tu código para **{{ config('app.name') }}** es:

# **{{ $otp }}**

Caduca en **5 minutos**.  
Si no lo solicitaste, ignora este correo.

Gracias,  
{{ config('app.name') }}
@endcomponent
