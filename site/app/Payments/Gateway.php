<?php
declare(strict_types=1);
namespace Chengyu\Payments;
interface Gateway
{
    public function create(array $sale): array;
    /** Returns pending, closed or paid; a paid result includes amount/currency/order/trade. */
    public function query(array $sale): array;
    public function refund(array $sale,array $refund): array;
    public function queryRefund(array $sale,array $refund): array;
    /** Verify authentic raw webhook data; returns the remote or local sale identifier. */
    public function notification(string $raw,array $headers): string;
}
