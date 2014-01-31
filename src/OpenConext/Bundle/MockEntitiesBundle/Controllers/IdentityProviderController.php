<?php

namespace OpenConext\Bundle\MockEntitiesBundle\Controllers;

use OpenConext\EngineTestStand\Fixture\MockIdpsFixtureAbstract;
use OpenConext\EngineTestStand\Saml2\ResponseFactory;
use \Symfony\Bundle\FrameworkBundle\Controller\Controller;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;
use \OpenConext\EngineTestStand\Saml2\Compat\Container;

class IdentityProviderController extends Controller
{
    /**
     * @Route("/{idpName}/metadata")
     *
     * @param $idpName
     * @return Response
     */
    public function metadataAction($idpName)
    {
        /** @var MockIdpsFixtureAbstract $idpFixture */
        $idpFixture = $this->get('fixture.idp');
        $entityDescriptor = $idpFixture->get($idpName);

        return new Response(
            $entityDescriptor->toXML()->ownerDocument->saveXML(),
            200,
            array('Content-Type' => 'application/xml')
        );
    }

    /**
     * @param $idpName
     * @return Response
     * @throws \RuntimeException
     */
    public function singleSignOnAction($idpName)
    {
        $redirectBinding = new \SAML2_HTTPRedirect();
        $message = $redirectBinding->receive();

        if (!$message instanceof \SAML2_AuthnRequest) {
            throw new \RuntimeException('Unknown message type: ' . get_class($message));
        }
        $authnRequest = $message;

        /** @var MockIdpsFixtureAbstract $idpFixture */
        $idpFixture = $this->get('fixture.idp');
        $entityDescriptor = $idpFixture->get($idpName);

        /** @var ResponseFactory $responseFactory */
        $responseFactory = $this->get('saml2.response_factory');
        $response = $responseFactory->createForEntityWithRequest($entityDescriptor, $authnRequest);

        $destination = (isset($entityDescriptor->Extensions['DestinationOverride']) ?
            $entityDescriptor->Extensions['DestinationOverride'] :
            ($authnRequest->getAssertionConsumerServiceURL() ?
                $authnRequest->getAssertionConsumerServiceURL() :
                $response->getDestination()));

        /** @var Container $container */
        $container = \SAML2_Utils::getContainer();
        $authnRequestXml = $container->getLastDebugMessageOfType(Container::DEBUG_TYPE_IN);
        $container->postRedirect(
            $destination,
            array(
                'authnRequestXml'=> htmlentities($authnRequestXml),
                'SAMLResponse' => base64_encode($response->xml),
            )
        );
        return $container->getPostResponse();
    }
}
