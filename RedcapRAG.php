<?php
namespace Stanford\RedcapRAG;

require_once "emLoggerTrait.php";

use \REDCapEntity\Entity;
use \REDCapEntity\EntityDB;
use \REDCapEntity\EntityFactory;

class RedcapRAG extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;
    const SecureChatInstanceModuleName = 'secure_chat_ai';

    public function __construct() {
        parent::__construct();
    }

    /**
     * Define entity types for the module.
     *
     * @return array $types
     */
    public function redcap_entity_types() {
        return [
            'generic_contextdb' => [
                'label' => 'Context Database',
                'label_plural' => 'Context Databases',
                'icon' => 'file',
                'properties' => [
                    'project_identifier' => [
                        'name' => 'Project Identifier',
                        'type' => 'text',
                        'required' => true,
                        'description' => 'A unique identifier for the project or scope to which the document belongs.',
                    ],
                    'content' => [
                        'name' => 'Content',
                        'type' => 'long_text',
                        'required' => true,
                    ],
                    'content_type' => [
                        'name' => 'Content Type',
                        'type' => 'text',
                        'required' => true,
                    ],
                    'file_url' => [
                        'name' => 'File URL',
                        'type' => 'text',
                        'required' => false,
                    ],
                    'upvotes' => [
                        'name' => 'Upvotes',
                        'type' => 'integer',
                        'required' => false,
                    ],
                    'downvotes' => [
                        'name' => 'Downvotes',
                        'type' => 'integer',
                        'required' => false,
                    ],
                    'source' => [
                        'name' => 'Source',
                        'type' => 'text',
                        'required' => false,
                    ],
                    'vector_embedding' => [
                        'name' => 'Vector Embedding',
                        'type' => 'long_text',
                        'required' => true,
                    ],
                    'meta_summary' => [
                        'name' => 'Meta Summary',
                        'type' => 'text',
                        'required' => false,
                    ],
                    'meta_tags' => [
                        'name' => 'Meta Tags',
                        'type' => 'text',
                        'required' => false,
                    ],
                    'meta_timestamp' => [
                        'name' => 'Meta Timestamp',
                        'type' => 'text',
                        'required' => false,
                    ]
                ],
            ]
        ];
    }

    /**
     * Trigger schema build for the entity when the module is enabled.
     *
     * @param string $version
     * @return void
     */
    public function redcap_module_system_enable($version) {
        try {
            \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
            $entityTypes = \REDCapEntity\EntityFactory::getInstance()->getEntityTypes();
            $this->emLog("Entity schema built. Available entity types: " . json_encode($entityTypes));
        } catch (\Exception $e) {
            $this->emError("Failed to build entity schema: " . $e->getMessage());
        }
    }

    /**
     * Retrieve the embedding for the given text using SecureChat AI.
     *
     * @param string $text
     * @return array|null
     */
    private function getEmbedding($text) {
        try {
            $result = $this->getSecureChatInstance()->callAI("ada-002", array("input" => $text));
            $this->emDebug("Generated embedding for content: " . substr($text, 0, 100));
            return $result['data'][0]['embedding'];
        } catch (\Exception $e) {
            $this->emError("Failed to generate embedding: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the most relevant documents from the context database.
     *
     * @param string $projectIdentifier
     * @param array $queryArray
     * @return array|null
     */
    public function getRelevantDocuments($projectIdentifier, $queryArray) {
        if (!is_array($queryArray) || empty($queryArray)) {
            return null;
        }

        $lastElement = end($queryArray);
        if (!isset($lastElement['role']) || $lastElement['role'] !== 'user' || !isset($lastElement['content'])) {
            return null;
        }

        $query = $lastElement['content'];
        $queryEmbedding = $this->getEmbedding($query);
        if (!$queryEmbedding) {
            return null;
        }

        $documents = [];

        if ($this->getSystemSetting('use_redis')) {
            try {
                $redis = new \Redis();
                $redis->connect($this->getSystemSetting('redis_server_address'), $this->getSystemSetting('redis_port'));

                $key = "chatbot_contexts:$projectIdentifier";
                $storedData = $redis->zRange($key, 0, -1);

                foreach ($storedData as $entry) {
                    $document = json_decode($entry, true);
                    $docEmbedding = json_decode($document['embedding'], true);
                    $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

                    $documents[] = [
                        'title' => $document['title'],
                        'content' => $document['content'],
                        'similarity' => $similarity,
                        'timestamp' => $document['timestamp']
                    ];
                }
            } catch (\Exception $e) {
                $this->emError("Failed to fetch documents from Redis: " . $e->getMessage());
                return null;
            }
        } else {
            try {
                $sql = 'SELECT id FROM `redcap_entity_generic_contextdb` WHERE project_identifier = "' . db_escape($projectIdentifier) . '"';
                $result = db_query($sql);

                while ($row = db_fetch_assoc($result)) {
                    $entity = $this->entityFactory->loadEntity('generic_contextdb', $row['id']);
                    $docEmbedding = json_decode($entity->getData()['vector_embedding'], true);
                    $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

                    $documents[] = [
                        'id' => $entity->getId(),
                        'content' => $entity->getData()['content'],
                        'source' => $entity->getData()['source'],
                        'meta_summary' => $entity->getData()['meta_summary'],
                        'meta_tags' => $entity->getData()['meta_tags'],
                        'meta_timestamp' => $entity->getData()['meta_timestamp'],
                        'similarity' => $similarity,
                    ];
                }
            } catch (\Exception $e) {
                $this->emError("Failed to fetch documents from Entity Table: " . $e->getMessage());
                return null;
            }
        }

        usort($documents, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($documents, 0, 3);
    }

    /**
     * Store a new document with its embedding vector.
     *
     * @param string $projectIdentifier
     * @param string $title
     * @param string $content
     * @return void
     */
    public function storeDocument($projectIdentifier, $title, $content) {
        $embedding = $this->getEmbedding($content);
        if (!$embedding) {
            $this->emError("Failed to generate embedding for content.");
            return;
        }
        $serialized_embedding = json_encode($embedding);

        if ($this->getSystemSetting('use_redis')) {
            try {
                $redis = new \Redis();
                $redis->connect($this->getSystemSetting('redis_server_address'), $this->getSystemSetting('redis_port'));

                $key = "chatbot_contexts:$projectIdentifier";
                if (!$redis->zAdd(
                    $key,
                    time(),
                    json_encode([
                        'title' => $title,
                        'content' => $content,
                        'embedding' => $serialized_embedding,
                        'timestamp' => time()
                    ])
                )) {
                    $this->emError("Failed to add document to Redis for project {$projectIdentifier}");
                }
            } catch (\Exception $e) {
                $this->emError("Failed to store document in Redis: " . $e->getMessage());
            }
        } else {
            try {
                $entity = new \REDCapEntity\Entity($this->entityFactory, 'generic_contextdb');
                $entity->setData([
                    'project_identifier' => $projectIdentifier,
                    'content' => $content,
                    'content_type' => 'text',
                    'file_url' => null,
                    'upvotes' => 0,
                    'downvotes' => 0,
                    'source' => $title,
                    'vector_embedding' => $serialized_embedding,
                    'meta_summary' => null,
                    'meta_tags' => null,
                    'meta_timestamp' => date("Y-m-d H:i:s"),
                ]);
                $entity->save();
            } catch (\Exception $e) {
                $this->emError("Failed to store document in Entity Table: " . $e->getMessage());
            }
        }
    }

    /**
     * Calculate the cosine similarity between two embedding vectors.
     *
     * @param array $vec1
     * @param array $vec2
     * @return float
     */
    private function cosineSimilarity($vec1, $vec2) {
        $dotProduct = 0;
        $normVec1 = 0;
        $normVec2 = 0;

        foreach ($vec1 as $key => $value) {
            $dotProduct += $value * ($vec2[$key] ?? 0);
            $normVec1 += $value ** 2;
        }

        foreach ($vec2 as $value) {
            $normVec2 += $value ** 2;
        }

        $normVec1 = sqrt($normVec1);
        $normVec2 = sqrt($normVec2);

        if ($normVec1 == 0 || $normVec2 == 0) {
            return 0;
        }

        return $dotProduct / ($normVec1 * $normVec2);
    }

    /**
     * Get the SecureChatAI instance from the module.
     *
     * @return \Stanford\SecureChatAI\SecureChatAI
     */
    public function getSecureChatInstance(): \Stanford\SecureChatAI\SecureChatAI {
        if (empty($this->secureChatInstance)) {
            $this->setSecureChatInstance(\ExternalModules\ExternalModules::getModuleInstance(self::SecureChatInstanceModuleName));
        }
        return $this->secureChatInstance;
    }

    /**
     * Set the SecureChatAI instance.
     *
     * @param \Stanford\SecureChatAI\SecureChatAI $secureChatInstance
     */
    public function setSecureChatInstance(\Stanford\SecureChatAI\SecureChatAI $secureChatInstance): void {
        $this->secureChatInstance = $secureChatInstance;
    }
}
