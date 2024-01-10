<?php

namespace App\Services\Vat;

use DragonBe\Vies\CheckVatResponse;
use App\DTO\Cart\VatValidateDTO;

interface VatValidateInterface
{
    public function checkVat(VatValidateDTO $dto): bool;

    public function getCompanyInfo(VatValidateDTO $dto): CheckVatResponse|array|null;
}
