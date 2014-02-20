<?php

namespace OpenConext\Component\EngineTestStand\Features\Context;

use OpenConext\Component\EngineBlock\LogChunkParser;
use OpenConext\Component\EngineBlockFixtures\IdFixture;
use OpenConext\Component\EngineBlockFixtures\IdFrame;
use OpenConext\Component\EngineTestStand\EntityRegistry;
use OpenConext\Component\EngineTestStand\MockServiceProvider;
use OpenConext\Component\EngineTestStand\Service\EngineBlock;
use OpenConext\Component\EngineTestStand\MockServiceProviderFactory;
use OpenConext\Component\EngineBlockFixtures\ServiceRegistryFixture;

/**
 * Class MockSpContext
 * @package OpenConext\Component\EngineTestStand\Features\Context
 */
class MockSpContext extends AbstractSubContext
{
    protected $mockSpRegistry;
    protected $mockIdpRegistry;
    protected $serviceRegistryFixture;
    protected $mockSpFactory;
    /**
     * @var EngineBlock
     */
    protected $engineBlock;

    /**
     * @param ServiceRegistryFixture $serviceRegistryFixture
     * @param EngineBlock $engineBlock
     * @param MockServiceProviderFactory $mockSpFactory
     * @param EntityRegistry $mockSpRegistry
     * @param EntityRegistry $mockIdpRegistry
     */
    public function __construct(
        ServiceRegistryFixture $serviceRegistryFixture,
        EngineBlock $engineBlock,
        MockServiceProviderFactory $mockSpFactory,
        EntityRegistry $mockSpRegistry,
        EntityRegistry $mockIdpRegistry
    ) {
        $this->serviceRegistryFixture = $serviceRegistryFixture;
        $this->engineBlock = $engineBlock;
        $this->mockSpFactory = $mockSpFactory;
        $this->mockSpRegistry = $mockSpRegistry;
        $this->mockIdpRegistry = $mockIdpRegistry;
    }


    /**
     * @When /^I trigger the login \(either at "([^"]*)" or unsollicited at EB\)$/
     */
    public function iTriggerTheLoginEitherAtOrUnsollicitedAtEb($spName)
    {
        /** @var MockServiceProvider $mockSp */
        $mockSp = $this->mockSpRegistry->get($spName);

        if ($mockSp->mustUseUnsolicited()) {
            $ssoStartLocation = $this->engineBlock->unsolicitedLocation($mockSp->entityId());
        }
        else {
            $ssoStartLocation = $mockSp->loginUrl();
        }

        $this->getMainContext()->getMinkContext()->visit($ssoStartLocation);
    }

    /**
     * @Given /^a Service Provider named "([^"]*)"$/
     */
    public function aServiceProviderNamedWithEntityid($name)
    {
        $mockSp = $this->mockSpFactory->createNew($name);
        $this->mockSpRegistry->set($name, $mockSp);
        $this->serviceRegistryFixture->registerSp($mockSp->entityId(), $mockSp->assertionConsumerServiceLocation());
    }

    /**
     * @Given /^SP "([^"]*)" may only access "([^"]*)"$/
     */
    public function spMayOnlyAccess($spName, $idpName)
    {
        $spEntityId = $this->mockSpRegistry->get($spName)->entityId();

        $idpEntityId = $this->mockIdpRegistry->get($idpName)->entityId();

        $this->serviceRegistryFixture->blacklist($spEntityId);
        $this->serviceRegistryFixture->allow($spEntityId, $idpEntityId);

        // Override the Destination for the Response
        $this->mockIdpRegistry->get($idpName)->overrideResponseDestination(
            $this->engineBlock->assertionConsumerLocation()
        );
    }

    /**
     * @Given /^SP "([^"]*)" is configured to generate a AuthnRequest like the one at "([^"]*)"$/
     */
    public function spIsConfiguredToGenerateAAuthnrequestLikeTheOneAt($spName, $authnRequestLogFile)
    {
        /** @var MockServiceProvider $mockSp */
        $mockSp = $this->mockSpRegistry->get($spName);

        $mockSpDefaultEntityId = $mockSp->entityId();
        $mockSpAcsLocation     = $mockSp->assertionConsumerServiceLocation();

        // First see if the request was even triggered by the SP, or if it was an unsolicited request
        // by EB.
        $logReader = new LogChunkParser($authnRequestLogFile);
        $unsolicitedRequest = $logReader->detectUnsolicitedRequest();
        if ($unsolicitedRequest) {
            $this->printDebug("Unsollicited Request:" . PHP_EOL . print_r($unsolicitedRequest, true));
            $mockSp->useUnsolicited();

            $requestIssuer = $unsolicitedRequest['saml:Issuer']['__v'];

            $frame = $this->engineBlock->getIdsToUse(IdFixture::FRAME_REQUEST);
            $frame->set(IdFrame::ID_USAGE_SAML2_REQUEST, $unsolicitedRequest['_ID']);
        } else {
            // If not, then parse an AuthnRequest out of the log file
            $authnRequest = $logReader->getMessage(LogChunkParser::MESSAGE_TYPE_AUTHN_REQUEST);
            $mockSp->setAuthnRequest($authnRequest);
            $this->printDebug(print_r($authnRequest, true));

            $requestIssuer = $authnRequest->getIssuer();
        }

        // Listen up Mock Service Provider, you must now pretend that you are the issuer of the request.
        $mockSp->setEntityId($requestIssuer);

        // Override the ACS Location for the SP used in the response to go to the Mock SP
        $this->serviceRegistryFixture
            ->remove($mockSpDefaultEntityId)
            ->setEntityAcsLocation($requestIssuer, $mockSpAcsLocation);
    }

    /**
     * @Given /^SP "([^"]*)" may run in transparent mode, if indicated in "([^"]*)"$/
     */
    public function spMayRunInTransparentModeIfIndicatedIn($spName, $sessionLogFIle)
    {
        $logReader = new LogChunkParser($sessionLogFIle);
        $entityId = $logReader->detectTransparentRequest();

        if (!$entityId) {
            return;
        }

        /** @var MockServiceProvider $mockSp */
        $mockSp = $this->mockSpRegistry->get($spName);
        $mockSp->useIdpTransparently($entityId);
    }

    /**
     * @Given /^SP "([^"]*)" does not require consent$/
     */
    public function spDoesNotRequireConsent($spName)
    {
        /** @var MockServiceProvider $mockSp */
        $mockSp = $this->mockSpRegistry->get($spName);

        $this->serviceRegistryFixture->setEntityNoConsent($mockSp->entityId());
    }
}
