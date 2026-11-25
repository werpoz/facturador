<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de bienvenida a un usuario nuevo, con el enlace para que ÉL cree su contraseña.
 * Nadie más la conoce: ni el administrador que lo agregó.
 */
class InvitacionUsuario extends Notification
{
    use Queueable;

    public function __construct(public string $url, public string $empresa) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Te dieron acceso a {$this->empresa}")
            ->greeting("Hola, {$notifiable->name}")
            ->line("Te agregaron como usuario de {$this->empresa} en ".config('app.name').'.')
            ->line('Para entrar, primero crea tu contraseña:')
            ->action('Crear mi contraseña', $this->url)
            ->line('El enlace vence en '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60).' minutos. Si venció, usa "¿Olvidaste tu contraseña?" en la pantalla de ingreso.')
            ->line('Si no esperabas este correo, puedes ignorarlo.');
    }
}
