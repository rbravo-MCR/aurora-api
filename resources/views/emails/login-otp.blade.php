@component('mail::message')
# Código de acceso

Tu código para **{{ $app }}** es:

# **{{ $code }}**

Caduca en 5 minutos. Si no lo solicitaste, ignora este correo.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
