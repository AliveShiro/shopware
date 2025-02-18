<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\BenchmarkBundle;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Shopware\Bundle\BenchmarkBundle\Exception\BenchmarkHydratingException;
use Shopware\Bundle\BenchmarkBundle\Exception\BenchmarkSendingException;
use Shopware\Bundle\BenchmarkBundle\Hydrator\HydratorInterface;
use Shopware\Bundle\BenchmarkBundle\Struct\BusinessIntelligenceRequest;
use Shopware\Bundle\BenchmarkBundle\Struct\BusinessIntelligenceResponse;
use Shopware\Bundle\BenchmarkBundle\Struct\BusinessIntelligenceResponse as BusinessIntelligenceResponseAlias;
use Shopware\Components\HttpClient\HttpClientInterface;
use Shopware\Components\HttpClient\Response;

class BusinessIntelligenceClient implements BusinessIntelligenceClientInterface
{
    private string $biEndpoint;

    private HttpClientInterface $client;

    /**
     * @var HydratorInterface<BusinessIntelligenceResponseAlias>
     */
    private HydratorInterface $biResponseHydrator;

    private BenchmarkEncryption $benchmarkEncryption;

    private LoggerInterface $logger;

    /**
     * @param HydratorInterface<BusinessIntelligenceResponse> $biResponseHydrator
     */
    public function __construct(
        string $biEndpoint,
        HttpClientInterface $client,
        HydratorInterface $biResponseHydrator,
        BenchmarkEncryption $benchmarkEncryption,
        LoggerInterface $logger = null
    ) {
        $this->biEndpoint = $biEndpoint;
        $this->client = $client;
        $this->biResponseHydrator = $biResponseHydrator;
        $this->benchmarkEncryption = $benchmarkEncryption;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws BenchmarkSendingException
     *
     * @return BusinessIntelligenceResponse
     */
    public function fetchBusinessIntelligence(BusinessIntelligenceRequest $biRequest)
    {
        $headers = [
            'User-Agent' => 'Shopware',
        ];

        try {
            $response = $this->client->get($this->biEndpoint . '?' . (string) $biRequest, $headers);
        } catch (Exception $ex) {
            $this->logger->warning(sprintf('Could not retrieve BI data from %s', $this->biEndpoint), [$ex]);

            throw new BenchmarkSendingException('Could not retrieve BI data', 0, $ex);
        }

        return $this->hydrateBiResponse($response);
    }

    /**
     * @throws BenchmarkHydratingException
     */
    private function hydrateBiResponse(Response $response): BusinessIntelligenceResponseAlias
    {
        $data = $response->getBody();

        if (empty($data)) {
            throw new BenchmarkHydratingException(sprintf('Could not retrieve BI response: %s', $data));
        }

        $this->verifyResponseSignature($response);

        return $this->biResponseHydrator->hydrate(['html' => $data]);
    }

    /**
     * @throws RuntimeException
     */
    private function verifyResponseSignature(Response $response): void
    {
        $signatureHeaderName = 'x-shopware-signature';
        $signature = $response->getHeader($signatureHeaderName);

        if (empty($signature)) {
            throw new RuntimeException(sprintf('Signature not found in header "%s"', $signatureHeaderName));
        }

        if (!$this->benchmarkEncryption->isSignatureSupported()) {
            return;
        }

        if ($this->benchmarkEncryption->isSignatureValid($response->getBody(), $signature)) {
            return;
        }

        throw new RuntimeException('Signature not valid');
    }
}
