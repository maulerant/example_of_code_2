<?php

namespace unit\Services\Vat;

use DragonBe\Vies\CheckVatResponse;
use Illuminate\Support\Str;
use App\DTO\Cart\VatValidateDTO;
use App\lib\MBOMSoapClient;
use App\Services\Vat\PolandValidateService;
use SoapHeader;
use SoapVar;
use Tests\TestCase;

use function PHPUnit\Framework\assertTrue;


class PolandValidateServiceTest extends TestCase
{
    public function testGetKey()
    {
        $service = new PolandValidateService();
        $result = $this->invokeProtectedMethod([$service, 'getKey'], []);

        if (defined('CONF_POLAND_NIP_CHECKER_KEY')) {
            $this->assertSame(CONF_POLAND_NIP_CHECKER_KEY, $result);
        } else {
            $this->assertSame(PolandValidateService::TEST_KEY, $result);
        }
    }

    public function testGetServiceURL()
    {
        $service = new PolandValidateService();
        $result = $this->invokeProtectedMethod([$service, 'getServiceURL'], []);

        if (defined('CONF_POLAND_NIP_CHECKER_PRODUCTION_URL')) {
            $this->assertSame(CONF_POLAND_NIP_CHECKER_PRODUCTION_URL, $result);
        } else {
            $this->assertSame(PolandValidateService::SERVICE_TEST_URL, $result);
        }
    }

    public function testGetWSDLFileURL()
    {
        $service = new PolandValidateService();
        $result = $this->invokeProtectedMethod([$service, 'getWSDLFileURL'], []);

        if (defined('CONF_POLAND_NIP_CHECKER_WSDL_URL')) {
            $this->assertSame(CONF_POLAND_NIP_CHECKER_WSDL_URL, $result);
        } else {
            $this->assertSame(PolandValidateService::WSDL_FILE_TEST_URL, $result);
        }
    }

    /**
     * @dataProvider getCompanyInfoFromApiDataProvider
     */
    public function testGetCompanyInfoFromApi(VatValidateDTO $dto, CheckVatResponse|null $apiResponse, ?array $expected)
    {
        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getAPIResponse', 'prepareCompanyInfo'])
            ->getMock();

        $service->expects($this->once())
            ->method('getAPIResponse')
            ->with($dto)
            ->willReturn($apiResponse);

        if ($apiResponse !== null) {
            $service->expects($this->once())
                ->method('prepareCompanyInfo')
                ->with($apiResponse)
                ->willReturn($expected);
        }

        $this->assertSame($expected, $service->getCompanyInfoFromApi($dto));
    }

    public function getCompanyInfoFromApiDataProvider(): array
    {
        $dto = (new VatValidateDTO())
            ->setVatNumber(Str::random(100));
        return [
            [
                'dto' => $dto,
                'apiResponse' => null,
                'expected' => null,
            ],
            [
                'dto' => $dto,
                'apiResponse' => new CheckVatResponse(),
                'expected' => ['prepared info'],
            ],

        ];
    }

    /**
     * @dataProvider getAPIResponseDataProvider
     */
    public function testGetAPIResponse(VatValidateDTO $dto, string $key, string $sid, ?CheckVatResponse $expected)
    {
        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['logon', 'getKey', 'getInfoByNIP'])
            ->getMock();

        $service->expects($this->once())
            ->method('logon')
            ->with($key)
            ->willReturn($sid);

        $service->expects($this->once())
            ->method('getKey')
            ->willReturn($key);

        if (! empty($sid)) {
            $service->expects($this->once())
                ->method('getInfoByNIP')
                ->with($sid, $dto->getVatNumber())
                ->willReturn($expected);
        }

        $this->assertSame($expected, $this->invokeProtectedMethod([$service, 'getAPIResponse'], [$dto]));
    }

    public function getAPIResponseDataProvider(): array
    {
        $dto = (new VatValidateDTO())
            ->setVatNumber(Str::random(100));
        return [
            [
                'dto' => $dto,
                'key' => Str::random(20),
                'sid' => '',
                'expected' => null,
            ],
            [
                'dto' => $dto,
                'key' => Str::random(10),
                'sid' => Str::random(50),
                'expected' => new CheckVatResponse(),
            ],

        ];
    }

    /**
     * @dataProvider isValidDataProvider
     */
    public function testIsValid(VatValidateDTO $dto, CheckVatResponse|null $apiResponse, bool $expected)
    {
        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getAPIResponse'])
            ->getMock();

        $service->expects($this->once())
            ->method('getAPIResponse')
            ->with($dto)
            ->willReturn($apiResponse);

        $this->assertSame($expected, $this->invokeProtectedMethod([$service, 'isValid'], [$dto]));
    }

    public function isValidDataProvider(): array
    {
        $dto = (new VatValidateDTO())
            ->setVatNumber(Str::random(100));
        return [
            [
                'dto' => $dto,
                'apiResponse' => null,
                'expected' => false,

            ],
            [
                'dto' => $dto,
                'apiResponse' => (new CheckVatResponse())->setValid(false),
                'expected' => false,

            ],
            [
                'dto' => $dto,
                'apiResponse' => (new CheckVatResponse())->setValid(true),
                'expected' => true,
            ],

        ];
    }

    public function testGetClient()
    {
        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getClientOptions'])
            ->getMock();

        $service->expects($this->once())
            ->method('getClientOptions')
            ->with('')
            ->willReturn([]);

        $client = $service->getClient();

        $this->assertInstanceOf(MBOMSoapClient::class, $client);

        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getClientOptions'])
            ->getMock();

        $sid = Str::random(10);
        $service->expects($this->once())
            ->method('getClientOptions')
            ->with($sid)
            ->willReturn([
                'sid' => $sid,
            ]);

        $client = $service->getClient($sid);

        $this->assertInstanceOf(MBOMSoapClient::class, $client);
    }

    public function testGetClientOptions()
    {
        /** @var PolandValidateService $service */
        $service = app(PolandValidateService::class);

        $result = $this->invokeProtectedMethod([$service, 'getClientOptions'], []);

        $this->assertIsArray($result);
        $this->assertFalse(isset($result['sid']));

        $sid = Str::random(10);
        $result = $this->invokeProtectedMethod([$service, 'getClientOptions'], [$sid]);

        $this->assertIsArray($result);
        $this->assertTrue(isset($result['sid']));
        $this->assertSame($sid, $result['sid']);
    }


    public function testGetZalogujHeaders()
    {
        /** @var PolandValidateService $service */
        $service = app(PolandValidateService::class);

        $result = $this->invokeProtectedMethod([$service, 'getZalogujHeaders'], []);
        $this->assertIsArray($result);

        foreach ($result as $header) {
            $this->assertInstanceOf(SoapHeader::class, $header);
        }
        /** @var SoapHeader $last */
        $last = last($result);
        $this->assertSame('Action', $last->name);
        $this->assertSame('http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj', $last->data);
    }

    public function testAppendDaneSzukajPodmiotyHeaders()
    {
        /** @var PolandValidateService $service */
        $service = app(PolandValidateService::class);

        $result = $this->invokeProtectedMethod([$service, 'getDaneSzukajPodmiotyHeaders'], []);
        $this->assertIsArray($result);

        foreach ($result as $header) {
            $this->assertInstanceOf(SoapHeader::class, $header);
        }
        /** @var SoapHeader $last */
        $last = last($result);
        $this->assertSame('Action', $last->name);
        $this->assertSame('http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty', $last->data);
    }

    public function testGetCommonSoapHeaders()
    {
        /** @var PolandValidateService $service */
        $service = app(PolandValidateService::class);

        $result = $this->invokeProtectedMethod([$service, 'getCommonSoapHeaders'], []);
        $this->assertIsArray($result);

        foreach ($result as $header) {
            $this->assertInstanceOf(SoapHeader::class, $header);
        }
        /** @var SoapHeader $last */
        $last = last($result);
        $this->assertSame('To', $last->name);
        $this->assertSame('https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc', $last->data);
    }


    public function testLogon()
    {
        $key = Str::random(100);

        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getClient', 'getZalogujHeaders'])
            ->getMock();

        /**
         * создание анонимного класса вынужденная мера, что б отдавать
         * объект типа  MBOMSoapClient
         */
        $client = new class extends MBOMSoapClient {
            public function __construct()
            {
            }

            public function __setSoapHeaders($headers = null,): bool
            {
                assertTrue(true);
                return true;
            }

            public function Zaloguj(array $data): object
            {
                assertTrue(true);
                return (object) [
                    'ZalogujResult' => $data['pKluczUzytkownika'],
                ];
            }
        };

        $service->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $testHeaders = [
            'testHeaders',
        ];

        $service->expects($this->once())
            ->method('getZalogujHeaders')
            ->willReturn($testHeaders);


        $result = $this->invokeProtectedMethod([$service, 'logon'], [$key]);

        $this->assertFalse(empty($result));
        $this->assertSame($key, $result);
    }

    public function testGetInfoByNIP()
    {
        $sid = Str::random(100);
        $nip = Str::random(100);

        $service = $this->getMockBuilder(PolandValidateService::class)
            ->onlyMethods(['getClient', 'getDaneSzukajPodmiotyHeaders', 'getSoapVar', 'parseInfo'])
            ->getMock();

        $response = Str::random(100);
        /**
         * создание анонимного класса вынужденная мера, что б отдавать
         * объект типа  MBOMSoapClient
         */
        $client = new class ($response) extends MBOMSoapClient {
            public function __construct(
                public string $response
            ) {
            }

            public function __setSoapHeaders($headers = null,): bool
            {
                assertTrue(true);
                return true;
            }

            public function DaneSzukajPodmioty(mixed $data): object
            {
                assertTrue(true);
                return (object) [
                    'DaneSzukajPodmiotyResult' => $this->response,
                ];
            }
        };

        $service->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $testHeaders = [
            'testHeaders',
        ];

        $service->expects($this->once())
            ->method('getDaneSzukajPodmiotyHeaders')
            ->willReturn($testHeaders);

        $service->expects($this->once())
            ->method('getSoapVar')
            ->with($nip)
            ->willReturn(new SoapVar('test', XSD_ANYTYPE));

        $service->expects($this->once())
            ->method('parseInfo')
            ->with('<?xml version="1.0" encoding="utf-8"?>' . $response)
            ->willReturn((new CheckVatResponse())->setValid(true));

        $result = $this->invokeProtectedMethod([$service, 'getInfoByNIP'], [$sid, $nip]);

        $this->assertInstanceOf(CheckVatResponse::class, $result);
        $this->assertTrue($result->isValid());
    }

    /**
     * @dataProvider parseInfoDataProvider
     */
    public function testParseInfo(string $soapXml, CheckVatResponse $expected)
    {
        /** @var PolandValidateService $service */
        $service = app(PolandValidateService::class);

        $result = $this->invokeProtectedMethod([$service, 'parseInfo'], [$soapXml]);

        $this->assertInstanceOf(CheckVatResponse::class, $result);
        $this->assertSame($expected->isValid(), $result->isValid());
    }

    public function parseInfoDataProvider(): array
    {
        return [
            [
                <<<EOL
<?xml version="1.0" encoding="utf-8"?>
         <root>
         <dane>
         <ErrorCode>4</ErrorCode>
         <ErrorMessagePl>Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.</ErrorMessagePl>
         <ErrorMessageEn>No data found for the specified search criteria.</ErrorMessageEn>
         <Nip>526104082</Nip>
         </dane>
         </root>
EOL,
                (new CheckVatResponse())
                    ->setValid(false),
            ],
            [
                '<?xml version="1.0" encoding="utf-8"?> <root></root>',
                (new CheckVatResponse())
                    ->setValid(false),

            ],
            [
                <<<EOL
<?xml version="1.0" encoding="utf-8"?>
                      <root>
            <dane>
              <Regon>000331501</Regon>
              <Nip>5261040828</Nip>
              <StatusNip />
              <Nazwa>GŁÓWNY URZĄD STATYSTYCZNY</Nazwa>
              <Wojewodztwo>MAZOWIECKIE</Wojewodztwo>
              <Powiat>m. st. Warszawa</Powiat>
              <Gmina>Śródmieście</Gmina>
              <Miejscowosc>Warszawa</Miejscowosc>
              <KodPocztowy>00-925</KodPocztowy>
              <Ulica>ul. Test-Krucza</Ulica>
              <NrNieruchomosci>208</NrNieruchomosci>
              <NrLokalu />
              <Typ>P</Typ>
              <SilosID>6</SilosID>
              <DataZakonczeniaDzialalnosci />
              <MiejscowoscPoczty>Warszawa</MiejscowoscPoczty>
            </dane>
          </root>
EOL,
                (new CheckVatResponse())
                    ->setValid(true),

            ],
        ];
    }
}
