<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Envío de correos con plantillas Twig (resources/views/emails).
 * Si MAIL_DSN es null:// o la app no está en producción, el contenido también se escribe en storage/logs/mail.log.
 */
final class MailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $mailLog,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return bool true si el correo se entregó al transporte real
     */
    public function send(string $to, string $subject, string $template, array $context = []): bool
    {
        $context += [
            'appName' => $this->config->string('app.name'),
            'appUrl' => $this->config->string('app.url'),
            'subject' => $subject,
        ];
        try {
            $html = $this->twig->render('emails/' . $template . '.html.twig', $context);
        } catch (\Throwable $e) {
            $this->logger->error('Error renderizando correo', ['template' => $template, 'error' => $e->getMessage()]);

            return false;
        }
        $text = self::htmlToText($html);
        $isNull = str_starts_with($this->config->string('mail.dsn'), 'null://');
        if ($isNull || !$this->config->isProduction()) {
            $this->mailLog->info(sprintf("Para: %s | Asunto: %s\n%s\n", $to, $subject, $text));
        }
        if ($isNull) {
            return false;
        }
        try {
            $email = (new Email())
                ->from(Address::create($this->config->string('mail.from')))
                ->to($to)
                ->subject($subject)
                ->text($text)
                ->html($html);
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo enviar el correo', ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $html) ?? $html;
        $html = preg_replace_callback(
            '#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#si',
            static fn (array $m): string => trim(strip_tags($m[2])) . ': ' . html_entity_decode($m[1]),
            $html,
        ) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/h[1-6]|/tr|/li)[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text) ?? $text;

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }
}
