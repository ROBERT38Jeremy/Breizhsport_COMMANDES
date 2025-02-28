<?php

namespace App\Service;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMqRpcClient
{
    private const TIMEOUT_SECONDS = 60;
    private \PhpAmqpLib\Channel\AbstractChannel|\PhpAmqpLib\Channel\AMQPChannel $channel;
    private mixed $callbackQueue;
    private $response;
    private $correlationId;
    private $connection;


    /**
     * @throws Exception
     */
    /**
     * @throws Exception
     */
    public function __construct()
    {
        try {
            $rabbitMqUri = getenv('RABBITMQ_URI');
            if (!$rabbitMqUri) {
                throw new Exception('RABBITMQ_URI is not set');
            }

            $parsedUrl = parse_url($rabbitMqUri);
            if (!$parsedUrl) {
                throw new Exception('Invalid RABBITMQ_URI format');
            }

            error_log("Tentative de connexion à RabbitMQ: " . $rabbitMqUri);

            $this->connection = new AMQPStreamConnection(
                $parsedUrl['host'] ?? 'localhost',
                $parsedUrl['port'] ?? 5672,
                $parsedUrl['user'] ?? 'user',
                $parsedUrl['pass'] ?? 'password',
            );

            error_log("Connexion établie avec succès, création du canal");
            $this->channel = $this->connection->channel();

            error_log("Canal créé avec succès, déclaration de la queue de callback");

            // Setup queue and consumer
            list($this->callbackQueue, ,) = $this->channel->queue_declare(
                '', false, false, true, false);
            error_log("Queue de callback créée : " . $this->callbackQueue);

            $this->channel->basic_consume(
                $this->callbackQueue,
                '',
                false,
                true,
                false,
                false,
                function($msg) {
                    $this->onResponse($msg);
                }
            );

            error_log("RabbitMqRpcClient initialisé avec succès");
        } catch (\Exception $e) {
            error_log("ERREUR lors de l'initialisation de RabbitMqRpcClient: " . $e->getMessage());
            // Nettoyage des ressources partiellement initialisées
            if (isset($this->channel)) {
                try { $this->channel->close(); } catch (\Exception $ex) {}
            }
            if (isset($this->connection) && $this->connection) {
                try { $this->connection->close(); } catch (\Exception $ex) {}
            }
            throw $e;
        }
    }

    private function onResponse(AMQPMessage $message): void
    {
        error_log("Réponse reçue avec correlation_id: " . $message->get('correlation_id'));
        error_log("Notre correlation_id attendu: " . $this->correlationId);

        if ($message->get('correlation_id') === $this->correlationId) {
            error_log("Correlation IDs correspondent, traitement de la réponse");
            $this->response = $message->body;
            error_log("Contenu de la réponse (brut): " . substr($this->response, 0, 200) . (strlen($this->response) > 200 ? '...' : ''));

            // Vérification de la validité du JSON
            $jsonDecoded = json_decode($this->response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("ERREUR: La réponse n'est pas un JSON valide: " . json_last_error_msg());
            } else {
                error_log("La réponse est un JSON valide avec " . count($jsonDecoded) . " clés de premier niveau");
            }
        } else {
            error_log("Correlation IDs ne correspondent pas, message ignoré");
        }
    }

    /**
     * @throws Exception
     */
    public function call(string $userId, string $uniqueId): array
    {
        $this->response = null;
        $this->correlationId = uniqid();

        error_log("Début de l'appel RPC avec userId: $userId, uniqueId: $uniqueId, correlationId: {$this->correlationId}");

        // Préparer le message à envoyer
        $messageContent = json_encode(['userId' => $userId, 'uniqueId' => $uniqueId]);
        error_log("Contenu du message à envoyer: $messageContent");

        try {
            // Vérifier si l'exchange existe
            error_log("Vérification/création de l'exchange 'PanierGetOne'");
            $this->channel->exchange_declare(
                'PanierGetOne',
                'direct',
                false,  // passive (false = créer si n'existe pas)
                false,   // durable
                false   // auto-delete
            );
            error_log("Exchange 'PanierGetOne' prêt");
        } catch (\Exception $e) {
            error_log("Erreur lors de la déclaration de l'exchange: " . $e->getMessage());
            throw new Exception('Failed to declare exchange: ' . $e->getMessage());
        }

        // Création et envoi du message
        $message = new AMQPMessage(
            $messageContent,
            [
                'correlation_id' => $this->correlationId,
                'reply_to' => $this->callbackQueue,
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]
        );

        error_log("Envoi du message à l'exchange 'PanierGetOne' avec routing key 'PanierGetOne'");
        $this->channel->basic_publish(
            $message,
            'PanierGetOne',    // exchange
            'PanierGetOne'     // routing key
        );
        error_log("Message publié avec succès");

        // Attente de la réponse avec gestion du timeout
        $startTime = time();
        error_log("Début de l'attente de la réponse (timeout: " . self::TIMEOUT_SECONDS . " secondes)");

        while (!$this->response) {
            $elapsedTime = time() - $startTime;
            if ($elapsedTime >= self::TIMEOUT_SECONDS) {
                error_log("TIMEOUT après $elapsedTime secondes");
                throw new Exception("Timeout waiting for Panier service after $elapsedTime seconds");
            }

            if ($elapsedTime > 0 && $elapsedTime % 5 == 0) {
                error_log("Toujours en attente de réponse... ($elapsedTime secondes écoulées)");
            }

            $this->channel->wait(null, false, 1);
        }

        error_log("Réponse reçue après " . (time() - $startTime) . " secondes");

        // Traitement de la réponse
        $decodedResponse = json_decode($this->response, true);
        if (!$decodedResponse) {
            error_log("ERREUR: Impossible de décoder la réponse JSON: " . json_last_error_msg());
            throw new Exception('Invalid response from Panier service: ' . json_last_error_msg());
        }

        error_log("Réponse décodée avec succès, structure: " . print_r(array_keys($decodedResponse), true));

        // Vérification des données attendues (suppression de la vérification de 'content')
        if (!isset($decodedResponse['_id']) || !isset($decodedResponse['products'])) {
            error_log("ERREUR: Réponse inattendue, clé '_id' ou 'products' manquante");
            throw new Exception("Unexpected response structure from Panier service");
        }

        // Retourner directement la réponse décodée sans chercher 'content'
        return $decodedResponse;

    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        try {
            if ($this->channel && method_exists($this->channel, 'is_open') && $this->channel->is_open()) {
                error_log("Fermeture du canal RabbitMQ");
                $this->channel->close();
            }

            if ($this->connection && method_exists($this->connection, 'isConnected') && $this->connection->isConnected()) {
                error_log("Fermeture de la connexion RabbitMQ");
                $this->connection->close();
            }
        } catch (\Exception $e) {
            error_log("Erreur lors de la fermeture des ressources RabbitMQ: " . $e->getMessage());
        }
    }
}