<?php

declare(strict_types=1);

namespace App\Services\Vat;

use DragonBe\Vies\CheckVatResponse;
use App\DTO\Cart\VatValidateDTO;
use App\lib\MBOMSoapClient;
use SoapClient;
use SoapHeader;
use SoapVar;
use Throwable;

use function Sentry\captureException;


class PolandValidateService implements VatValidateInterface
{
    use VatCheckTrait;

    private const REAL_VAT_FOR_TESTS = '5261040828';

    public const SERVICE_TEST_URL = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';

    public const WSDL_FILE_TEST_URL = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/wsdl/UslugaBIRzewnPubl-ver11-test.wsdl';
    public const TEST_KEY = 'abcde12345abcde12345';

    protected SoapClient $client;

    public function getClient(string $sid = ''): MBOMSoapClient
    {
        return new MBOMSoapClient($this->getWSDLFileURL(), $this->getClientOptions($sid));
    }

    protected function getClientOptions(string $sid = ''): array
    {
        $options = [
            'location' => $this->getServiceURL(),
            'soap_version' => SOAP_1_2,
            'style' => SOAP_DOCUMENT,
            'use' => SOAP_ENCODED,
            'exceptions' => true,
            'trace' => 1,
            'connection_timeout' => 25,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'authentication' => SOAP_AUTHENTICATION_DIGEST,
        ];

        if (! empty($sid)) {
            $options['sid'] = $sid;
        }

        return $options;
    }

    public function checkVat(VatValidateDTO $dto): bool
    {
        try {
            return $this->isValid($dto);
        } catch (Throwable $e) {
            captureException($e);
        }
        return false;
    }

    public function isValid(VatValidateDTO $dto): bool
    {
        $apiResponse = $this->getAPIResponse($dto);
        if ($apiResponse === null) {
            return false;
        }
        return $apiResponse->isValid();
    }

    public function getCompanyInfo(VatValidateDTO $dto): CheckVatResponse|array|null
    {
        try {
            return $this->getCompanyInfoFromApi($dto);
        } catch (Throwable $e) {
            captureException($e);
        }
        return null;
    }

    public function getCompanyInfoFromApi(VatValidateDTO $dto): CheckVatResponse|array|null
    {
        $apiResponse = $this->getAPIResponse($dto);
        if ($apiResponse === null) {
            return null;
        }

        return $this->prepareCompanyInfo($apiResponse);
    }

    protected function getAPIResponse(VatValidateDTO $dto): CheckVatResponse|null
    {
        $sid = $this->logon($this->getKey());

        if (empty($sid)) {
            return null;
        }

        return $this->getInfoByNIP($sid, $dto->getVatNumber());
    }

    protected function getCommonSoapHeaders(): array
    {
        return [
            new \SoapHeader(
                'http://www.w3.org/2005/08/addressing',
                'To',
                'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'
            ),
        ];
    }

    protected function logon(string $key): string
    {
        $client = $this->getClient();

        $headers = $this->getZalogujHeaders();
        $client->__setSoapHeaders($headers);

        $result = $client->Zaloguj(['pKluczUzytkownika' => $key,]);

        return (string) $result?->ZalogujResult;
    }

    protected function getInfoByNIP(string $sid, string $nip): CheckVatResponse
    {
        $client = $this->getClient($sid);

        $headers = $this->getDaneSzukajPodmiotyHeaders();
        $client->__setSoapHeaders($headers);

        $var = $this->getSoapVar($nip);

        $response = $client->DaneSzukajPodmioty($var);
        /**
         * как результат возвращается <![CDATA>, потому конвертация в хмл и его парсинг
         */
        return $this->parseInfo('<?xml version="1.0" encoding="utf-8"?>' . $response->DaneSzukajPodmiotyResult);
    }

    protected function parseInfo(string $soapXml): CheckVatResponse
    {
        $xml = simplexml_load_string($soapXml);
        /**
         * if error
         * <?xml version="1.0" encoding="utf-8"?>
         * <root>
         * <dane>
         * <ErrorCode>4</ErrorCode>
         * <ErrorMessagePl>Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.</ErrorMessagePl>
         * <ErrorMessageEn>No data found for the specified search criteria.</ErrorMessageEn>
         * <Nip>526104082</Nip>
         * </dane>
         * </root>
         */
        if (empty($xml) || empty($xml->dane) || isset($xml->dane->ErrorCode)) {
            return new CheckVatResponse([
                'valid' => false,
                'countryCode' => '',
                'vatNumber' => (string) ($xml->dane?->Nip ?? ''),
                'requestDate' => now(),
            ]);
        }
        /**
         * if correct
         * <?xml version="1.0" encoding="utf-8"?>
         *             <root>
         *   <dane>
         *     <Regon>000331501</Regon>
         *     <Nip>5261040828</Nip>
         *     <StatusNip />
         *     <Nazwa>GŁÓWNY URZĄD STATYSTYCZNY</Nazwa>
         *     <Wojewodztwo>MAZOWIECKIE</Wojewodztwo>
         *     <Powiat>m. st. Warszawa</Powiat>
         *     <Gmina>Śródmieście</Gmina>
         *     <Miejscowosc>Warszawa</Miejscowosc>
         *     <KodPocztowy>00-925</KodPocztowy>
         *     <Ulica>ul. Test-Krucza</Ulica>
         *     <NrNieruchomosci>208</NrNieruchomosci>
         *     <NrLokalu />
         *     <Typ>P</Typ>
         *     <SilosID>6</SilosID>
         *     <DataZakonczeniaDzialalnosci />
         *     <MiejscowoscPoczty>Warszawa</MiejscowoscPoczty>
         *   </dane>
         * </root>
         */
        $data = $xml->dane;
        return new CheckVatResponse([
            'valid' => true,
            'vatNumber' => (string) $data->Nip,
            'requestDate' => now(),
            'countryCode' => (string) $data->Regon,
            'name' => (string) $data->Nazwa,
            'traderAddress' => "{$data->Ulica}, nr {$data->NrNieruchomosci}, lok. {$data->NrLokalu} {$data->KodPocztowy} {$data->Miejscowosc} Poľsko",
            'traderPostcodeMatch' => (string) $data->KodPocztowy,
            'traderCityMatch' => (string) $data->Miejscowosc,
            'traderStreetMatch' => (string) $data->Ulica,
            'traderCompanyTypeMatch' => (string) $data->Typ,
            'traderNameMatch' => (string) $data->Nazwa,
        ]);
    }

    protected function getServiceURL(): string
    {
        return getConst('CONF_POLAND_NIP_CHECKER_PRODUCTION_URL', self::SERVICE_TEST_URL);
    }

    protected function getWSDLFileURL(): string
    {
        return getConst('CONF_POLAND_NIP_CHECKER_WSDL_URL', self::WSDL_FILE_TEST_URL);
    }

    protected function getKey(): string
    {
        return getConst('CONF_POLAND_NIP_CHECKER_KEY', self::TEST_KEY);
    }

    protected function getZalogujHeaders(): array
    {
        $headers = $this->getCommonSoapHeaders();
        $headers[] = new SoapHeader(
            'http://www.w3.org/2005/08/addressing',
            'Action',
            'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj'
        );
        return $headers;
    }

    protected function getDaneSzukajPodmiotyHeaders(): array
    {
        $headers = $this->getCommonSoapHeaders();
        $headers[] = new SoapHeader(
            'http://www.w3.org/2005/08/addressing',
            'Action',
            'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty'
        );
        return $headers;
    }

    protected function getSoapVar(string $nip): SoapVar
    {
        $xml = "<ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>{$nip}</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty> ";

        $var = new SoapVar(
            $xml,
            XSD_ANYXML,
            '',
            '',
            'dat',
            "http://CIS/BIR/PUBL/2014/07/DataContract"
        );
        return $var;
    }
}
