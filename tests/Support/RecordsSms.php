<?php

namespace Tests\Support;

use App\Contracts\SmsSender;

/** Faux envoyeur SMS de test : enregistre les envois en mémoire. */
class RecordsSms implements SmsSender
{
    /** @var array<int, array{0:string,1:string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = [$phone, $message];
    }
}
