<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class OutboundMimeMessageFactory
{
    public function create(OutboundMessage $message): Email
    {
        $email = (new Email())
            ->from(new Address($message->senderAddress, $message->senderName))
            ->to($message->recipient)
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);
        if (null !== $message->replyTo) {
            $email->replyTo($message->replyTo);
        }
        $email->getHeaders()->addIdHeader('Message-ID', $message->messageId);
        if (null !== $message->unsubscribeUrl) {
            if (1 !== preg_match('#^https://[^\r\n<> ]+$#D', $message->unsubscribeUrl)) {
                throw new DeliveryTransportFailure('permanent', 'Unsubscribe URL is invalid.');
            }
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$message->unsubscribeUrl.'>');
            if ($message->oneClickUnsubscribe) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        } elseif ($message->oneClickUnsubscribe) {
            throw new DeliveryTransportFailure('permanent', 'One-click unsubscribe requires an HTTPS unsubscribe URL.');
        }

        return $email;
    }
}
