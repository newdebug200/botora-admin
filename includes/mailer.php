<?php
require_once __DIR__ . '/../config.php';

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

function send_botora_email(string $to, string $subject, string $text): bool {
  if (!class_exists(Transport::class) || !class_exists(Email::class)) {
    error_log('[Botora Mailer] Symfony Mailer absent. Exécutez composer install.');
    return false;
  }

  $from = defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'no-reply@botora.local';
  $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'Botora Admin';

  try {
    $email = (new Email())
      ->from(sprintf('%s <%s>', $fromName, $from))
      ->to($to)
      ->subject($subject)
      ->text($text);
    (new Mailer(Transport::fromDsn(MAILER_DSN)))->send($email);
    return true;
  } catch (TransportExceptionInterface $e) {
    error_log('[Botora Mailer] Transport SMTP échoué : ' . $e->getMessage());
  } catch (Throwable $e) {
    error_log('[Botora Mailer] Envoi échoué : ' . $e->getMessage());
  }
  return false;
}
