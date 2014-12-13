<?php
namespace publisher;

use org\wso2\carbon\databridge\commons\thrift\exception\ThriftNoStreamDefinitionExistException;
use org\wso2\carbon\databridge\commons\thrift\service\secure\ThriftSecureEventTransmissionServiceClient;
use org\wso2\carbon\databridge\commons\thrift\exception\ThriftSessionExpiredException;
use org\wso2\carbon\databridge\commons\thrift\exception\ThriftDifferentStreamDefinitionAlreadyDefinedException;
use org\wso2\carbon\databridge\commons\thrift\exception\ThriftMalformedStreamDefinitionException;
use org\wso2\carbon\databridge\commons\thrift\exception\ThriftStreamDefinitionException;
use publisher\NullPointerException;
use publisher\MalformedURLException;

class Publisher
{

    /**
     * Array consisting Receiver URL scheme, host, port
     *
     * @var array
     */
    private $receiverURL;

    /**
     * Array consisting Authentication URL scheme, host, port
     *
     * @var array
     */
    private $authenticationURL;

    /**
     * Authentication username
     *
     * @var String
     */
    private $username;

    /**
     * Authentication username
     *
     * @var String
     */
    private $password;

    /**
     *
     * @var PublisherConnector
     */
    private $connector;

    /**
     *
     * @var log4j Logger
     */
    private $log;

    /**
     *
     * @param String $receiverURL
     *            thrift TCP url and port
     *            ex: tcp://192.168.1.1:7611
     * @param String $username            
     * @param String $password            
     * @param String $authenticationURL
     *            BAM secure server
     *            ex: https://192.168.1.1:9443
     *            
     * @throws NullPointerException
     */
    
    
    public function __construct($receiverURL, $username, $password, $authenticationURL = NULL)
    {
        \Logger::configure(PublisherConstants::LOG4J_CONFIG_FILE_PATH);
        $this->log = \Logger::getLogger(PublisherConstants::LOGGER_NAME);
        if ($receiverURL) {
            $this->setReceiverURL($receiverURL);
        } else {
            $error = 'Receiver URL cannot be NULL';
            throw new NullPointerException($error);
        }
        
        $this->setAuthenticationURL($authenticationURL);
        $this->username = $username;
        $this->password = $password;
        $this->connector = new PubllisherConnector($this->receiverURL, $this->authenticationURL, $username, $password);
    }

    /**
     * Processes the publish URL
     *
     * @param array $receiverURL            
     * @throws NullPointerException
     */
    private function setReceiverURL($receiverURL)
    {
        if (strpos($receiverURL, 'localhost') !== FALSE) {
            str_replace('localhost', '127.0.0.1', $receiverURL);
            $this->log->info('Changing receiver url from \'localhost\' to 127.0.0.1');
        }
        
        $url = parse_url($receiverURL);
        
        if (isset($url['path']) && ! isset($url['host'])) {
            
            if (filter_var($url['path'], FILTER_VALIDATE_IP)) {
                $url['host'] = $url['path'];
            }
        }
        
        if (array_key_exists('host', $url)) {
            if (! array_key_exists('scheme', $url)) {
                // using tcp by default
                $url['scheme'] = 'tcp';
                $this->log->info('Receiver url scheme not defined, Using TCP.');
            }
            if (! array_key_exists('port', $url)) {
                // using default thrift receiver port
                $url['port'] = PublisherConstants::DEFAULT_THRIFT_RECEIVER_PORT;
                $this->log->info('Receiver url port not defined, Using port: ' . $url['port']);
            }
            
            $this->receiverURL = $url;
        } else {
            $error = "Invalid Receiver URL '" . $receiverURL . "'. Receiver URL should be in the form of [tcp|ssl]://[host]:[port]";
            throw new NullPointerException($error);
        }
    }

    /**
     * Processes the authentication URL
     *
     * @param array $authenticationURL            
     * @throws MalformedURLException
     */
    private function setAuthenticationURL($authenticationURL)
    {
        if ($authenticationURL) {
            
            $url = parse_url($authenticationURL);
            
            if (isset($url['path']) && ! isset($url['host'])) {
                
                if (filter_var($url['path'], FILTER_VALIDATE_IP) | $url['path'] == 'localhost') {
                    $url['host'] = $url['path'];
                    unset($url['path']);
                }
            }
            
            if (array_key_exists('host', $url)) {
                if (! array_key_exists('port', $url)) {
                    // using default thrift receiver port
                    $url['port'] = PublisherConstants::DEFAULT_BAM_SECURE_PORT;
                    $this->log->info('BAM secure server port not defined, Using port: ' . $url['port']);
                }
                if (! array_key_exists('scheme', $url)) {
                    // using https by default
                    $url['scheme'] = 'https';
                    $this->log->info('BAM secure server url scheme not defined, Using https.');
                } elseif ($url['scheme'] != 'https') {
                    
                    $this->log->info('BAM secure server url scheme is not https. Provided \''.$url['scheme'].'\' instead. Switching to https');
                }
                
                $this->authenticationURL = $url;
            } else {
                $error = "Invalid BAM secure server URL: " . $authenticationURL . ". The URL should be in the form of https://[host]:[port]";
                throw new MalformedURLException($error);
            }
        } else { // if authentication url is not defined construct from receiver url
            $this->authenticationURL = array(
                'scheme' => 'https',
                'host' => $this->receiverURL['host'],
                'port' => PublisherConstants::DEFAULT_BAM_SECURE_PORT
            );
            $this->log->info('BAM secure server url not defined, Using :' . $this->authenticationURL['scheme'] . "://" . $this->authenticationURL['host'] . ':' . $this->authenticationURL['port']);
        }
    }

    /**
     * Search a stream definition, given the stream name and the version
     *
     * @param string $streamName            
     * @param string $streaVersion            
     * @return string StreamID if exist else FALSE
     *        
     * @throws StreamDefinitionException
     */
    public function findStream($streamName, $streamVersion)
    {
        try {
            return $this->connector->getPublisherClient()->findStreamId($this->connector->getSessionId(), $streamName, $streamVersion);
        } catch (ThriftNoStreamDefinitionExistException $e) {
            throw new StreamDefinitionException('Stream definition: ' . $streamName . ':' . $streamVersion . ' not found', $e);
        } catch (ThriftSessionExpiredException $e) {
            $this->log->error('Session expired.');
        }
    }

    /**
     * Adds a stream definition to BAM
     *
     * @param string $streamDefinision            
     * @return string stream ID upon successfull insertion
     *        
     * @throws StreamDefinitionException
     */
    public function addStreamDefinition($streamDefinision)
    {
        try {
            return $this->connector->getPublisherClient()->defineStream($this->connector->getSessionId(), $streamDefinision);
        } catch (ThriftDifferentStreamDefinitionAlreadyDefinedException $e) {
            throw new StreamDefinitionException('Stream definition already exist!', $e);
        } catch (ThriftMalformedStreamDefinitionException $e) {
            throw new StreamDefinitionException('Malformed stream definition!', $e);
        } catch (ThriftStreamDefinitionException $e) {
            throw new StreamDefinitionException('Error adding the stream definition!', $e);
        }
    }

    /**
     * Publish event to BAM server
     *
     * @param Event $event            
     * @throws UnknownAttributeException
     */
    public function publish($event)
    {
        // $connector = new ThriftSecureEventTransmissionServiceClient($input); //<-remove!!
        $eventBundle = ThriftEventConverter::covertToThriftBundle($event, $this->connector->getSessionId());
        $this->connector->getPublisherClient()->publish($eventBundle);
    }
}