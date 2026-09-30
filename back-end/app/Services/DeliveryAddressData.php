<?php

namespace App\Services;

use App\Models\CustomerAddress;

final readonly class DeliveryAddressData
{
    public function __construct(public string $city, public string $district, public ?string $latitude = null, public ?string $longitude = null) {}

    public static function fromAddress(CustomerAddress $address): self
    {
        return new self($address->city, $address->district, $address->latitude, $address->longitude);
    }

    /** @param array{city:string,district:string,latitude?:numeric-string|float|int|null,longitude?:numeric-string|float|int|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['city'], $data['district'], isset($data['latitude']) ? (string) $data['latitude'] : null, isset($data['longitude']) ? (string) $data['longitude'] : null);
    }

    public function isValid(): bool
    {
        return AddressNormalizer::normalize($this->city) !== '' && AddressNormalizer::normalize($this->district) !== ''
            && (($this->latitude === null && $this->longitude === null)
                || ($this->latitude !== null && $this->longitude !== null && is_numeric($this->latitude) && is_numeric($this->longitude)
                    && is_finite((float) $this->latitude) && is_finite((float) $this->longitude)
                    && abs((float) $this->latitude) <= 90 && abs((float) $this->longitude) <= 180));
    }
}
