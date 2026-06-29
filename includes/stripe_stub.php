<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

class StripeClientStub
{
    public function createCharge(float $amount, string $currency, string $source, string $description): array
    {
        // Dummy Stripe implementation
        return [
            'id' => 'ch_' . bin2hex(random_bytes(10)),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'succeeded',
            'description' => $description,
            'paid' => true,
        ];
    }
}
