<?php
namespace Stanford\RedcapRAG;

require_once "emLoggerTrait.php";

$entityBase = dirname(__DIR__, 1) . '/redcap_entity_v9.9.9/classes/';
require_once $entityBase . 'Page.php';
require_once $entityBase . 'EntityFormTrait.php';
require_once $entityBase . 'Entity.php';
require_once $entityBase . 'EntityFactory.php';
require_once $entityBase . 'EntityDB.php';
require_once $entityBase . 'EntityList.php';
require_once $entityBase . 'EntityForm.php';
require_once $entityBase . 'EntityQuery.php';
require_once $entityBase . 'EntityDeleteForm.php';
require_once $entityBase . 'SchemaManagerPage.php';
require_once $entityBase . 'StatusMessageQueue.php';


use \REDCapEntity\Entity;
use \REDCapEntity\EntityDB;
use \REDCapEntity\EntityFactory;

class RedcapRAG extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;
    const SecureChatInstanceModuleName = 'secure_chat_ai';

    private $entityFactory;

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
                    ],
                    'hash' => [
                        'name' => 'Hash',
                        'type' => 'text',
                        'required' => true,
                        'description' => 'A unique hash for deduplication purposes.',
                    ],
                    'created' => [
                        'name' => 'Created',
                        'type' => 'integer',
                        'required' => false, // It will be auto-generated
                        'description' => 'Timestamp for when the record was created.',
                    ],

                ],
            ],
        ];
    }

    private function getEntityFactory() {
        if (!$this->entityFactory) {
            $this->entityFactory = new \REDCapEntity\EntityFactory($this->PREFIX);
        }
        return $this->entityFactory;
    }

    /**
     * Trigger schema build for the entity when the module is enabled.
     *
     * @param string $version
     * @return void
     */
    public function redcap_module_system_enable($version) {
        try {
            // Build the schema for the RAG module
            \REDCapEntity\EntityDB::buildSchema($this->PREFIX);

            // Get the EntityFactory instance
            $entityFactory = $this->getEntityFactory();

            // Fetch and log available entity types
            $entityTypes = $entityFactory->getEntityTypes();
            $this->emLog("Entity schema built. Available entity types: " . json_encode($entityTypes));
        } catch (\Exception $e) {
            $this->emError("Failed to build entity schema: " . $e->getMessage());
        }
    }




    /**
     *
     */
    private function getEmbedding($text) {
        try {
            // Call the embedding API
            $result = $this->getSecureChatInstance()->callAI("ada-002", array("input" => $text));

            // Log the response for debugging
            // $this->emDebug("API response for embedding:", $result);

            // Extract and return the embedding
            if (isset($result['data'][0]['embedding'])) {
                $this->emDebug("Generated embedding for content: " . substr($text, 0, 100));
                return $result['data'][0]['embedding'];
            } else {
                $this->emError("Unexpected API response: " . json_encode($result));
            }
        } catch (\Exception $e) {
            $this->emError("Failed to generate embedding: " . $e->getMessage());
        }
        return null; // Return null if embedding generation fails
    }

    /**
     * Retrieve the most relevant documents from the context database.
     *
     * @param string $projectIdentifier
     * @param array $queryArray
     * @return array|null
     */
    public function getRelevantDocuments($projectIdentifier, $queryArray, $topK=3) {
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

        // sparse query vector for hybrid
        $sparseQuery = $this->generateSparseVector($query);

        $documents = [];
        if ($this->isVectorDbEnabled()) {
            try {
                $namespace = $projectIdentifier;
                $candidateK = intval($this->getSystemSetting('hybrid_candidate_k') ?? 20);

                // --- Dense semantic search ---
                $denseResults = [];
                try {
                    $denseResults = $this->pineconeQuery($namespace, $queryEmbedding, $candidateK);
                } catch (\Exception $e) {
                    $this->emError("Dense query failed: ".$e->getMessage());
                }

                // --- Sparse keyword search ---
                $sparseResults = [];
                try {
                    $sparseResults = $this->pineconeSparseQuery($namespace, $sparseQuery, $candidateK);
                } catch (\Exception $e) {
                    $this->emError("Sparse query failed: ".$e->getMessage());
                }

                // Normalize and combine
                $combined = $this->mergeHybridResults($denseResults, $sparseResults , $candidateK);
                // Slice to final topK for RAG / caller
                $combined = array_slice($combined, 0, $topK);

                // Format top matches
                $documents = [];
                foreach ($combined as $m) {
                    $meta = $m['metadata'] ?? [];

                    $documents[] = [
                        'id'            => $m['id'] ?? null,
                        'content'       => $meta['content'] ?? '',
                        'source'        => $meta['source'] ?? '',
                        'meta_summary'  => $meta['meta_summary'] ?? null,
                        'meta_tags'     => $meta['meta_tags'] ?? null,
                        'meta_timestamp'=> $meta['timestamp'] ?? null,
                        'dense'         => $m['dense_score']  ?? null,
                        'sparse'        => $m['sparse_score'] ?? null,
                        'similarity'    => $m['hybrid_score'] ?? 0,
                    ];
                }

                return $documents;

            } catch (\Exception $e) {
                $this->emError("Pinecone query failed: " . $e->getMessage());
                return null;
            }
        } else {
            try {
                $sql = 'SELECT id FROM `redcap_entity_generic_contextdb` WHERE project_identifier = "' . db_escape($projectIdentifier) . '"';
                $result = db_query($sql);

                $entityFactory = $this->getEntityFactory();
                while ($row = db_fetch_assoc($result)) {
                    $entity = $entityFactory->getInstance('generic_contextdb', $row['id']);
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

        $top_matches = array_slice($documents, 0, $topK);
        return $top_matches;
    }

    private function mergeHybridResults($dense, $sparse, $topK = 3) {
        $merged = [];

        // Dense map (id => score)
        $denseMap = [];
        foreach (($dense['matches'] ?? []) as $m) {
            $denseMap[$m['id']] = $m['score'];
        }
        // $this->emDebug("Dense map", $denseMap);

        // Sparse map (id => score)
        $sparseMap = [];
        foreach (($sparse['matches'] ?? []) as $m) {
            $sparseNorm = log(1 + $m['score']);
            $sparseMap[$m['id']] = $sparseNorm;
        }
        $this->emDebug("Sparse map", $sparse);

        // All IDs merged
        $allIds = array_unique(array_merge(array_keys($denseMap), array_keys($sparseMap)));
        $this->emDebug("All unique IDs", $allIds);

        $wDense  = floatval($this->getSystemSetting('hybrid_dense_weight') ?? 0.6);
        $wSparse = floatval($this->getSystemSetting('hybrid_sparse_weight') ?? 0.4);

        foreach ($allIds as $id) {
            $d = $denseMap[$id]  ?? 0;
            $s = $sparseMap[$id] ?? 0;

            // Hybrid weighting
            $hybrid = ($wDense * $d) + ($wSparse * $s);

            // Metadata retrieval
            $metaDense = null;
            foreach (($dense['matches'] ?? []) as $m) {
                if ($m['id'] === $id) $metaDense = $m;
            }

            $metaSparse = null;
            foreach (($sparse['matches'] ?? []) as $m) {
                if ($m['id'] === $id) $metaSparse = $m;
            }

            $chosen = $metaDense ?? $metaSparse;
            $chosen['hybrid_score'] = $hybrid;
            $chosen['dense_score']  = $d;
            $chosen['sparse_score'] = $s;
            $chosen['similarity']   = $hybrid;

            $merged[] = $chosen;
        }

        // Sort by hybrid_score
        usort($merged, fn($a,$b) => ($b['hybrid_score'] <=> $a['hybrid_score']));
        $merged = array_slice($merged, 0, $topK);
        // $this->emDebug("Final merged (sorted)", $merged);
        return $merged;
    }

    /**
     * Store a new document with its embedding vector.
     *
     * @param string $projectIdentifier
     * @param string $title
     * @param string $content
     * @return void
     */
    public function storeDocument($projectIdentifier, $title, $content, $dateCreated = null) {
        $embedding = $this->getEmbedding($content);
        if (!$embedding) {
            $this->emError("Failed to generate embedding for content.");
            return;
        }
        $serialized_embedding = json_encode($embedding);
        $contentHash = hash('sha256', $content);

        if ($this->isVectorDbEnabled()) {
            $namespace = $projectIdentifier;

            $sparse = $this->generateSparseVector($content, 'passage');

            // Dense upsert (serverless)
            $this->pineconeUpsert($namespace, [
                [
                    "id"     => $contentHash,
                    "values" => $embedding,
                    "metadata" => [
                        "title"     => $title,
                        "content"   => $content,
                        "source"    => $title,
                        "hash"      => $contentHash,
                        "timestamp" => $dateCreated ?? time(),
                    ]
                ]
            ]);

            // Sparse upsert (pod index)
            $this->pineconeSparseUpsert($namespace, [
                [
                    "id"           => $contentHash,
                    "sparse_values"=> $sparse,
                    "metadata"     => [
                        "title"     => $title,
                        "content"   => $content,
                        "source"    => $title,
                        "hash"      => $contentHash,
                        "timestamp" => $dateCreated ?? time(),
                    ]
                ]
            ]);

            $this->emDebug("Pinecone upserted chunk $contentHash in namespace $namespace");
            return;
        } else {
            // Handle deduplication in the Entity Table
            try {
                $query = "SELECT id FROM redcap_entity_generic_contextdb
                      WHERE project_identifier = ?
                      AND hash = ?";
                $params = [$projectIdentifier, $contentHash];
                $result = $this->query($query, $params);

                if ($result->num_rows > 0) {
                    $this->emDebug("Document already exists in Entity Table for project {$projectIdentifier}. Skipping.");
                    return; // Skip storing duplicate
                }

                $entityFactory = $this->getEntityFactory();

                $entity = new \REDCapEntity\Entity($entityFactory, 'generic_contextdb');
                $data = [
                    'project_identifier' => $projectIdentifier,
                    'content' => $content,
                    'content_type' => 'text',
                    'vector_embedding' => $serialized_embedding,
                    'hash' => $contentHash,// Store hash for deduplication

                    'file_url' => null,
                    'upvotes' => 0,
                    'downvotes' => 0,
                    'source' => $title,
                    'meta_summary' => null,
                    'meta_tags' => null,
                    'meta_timestamp' => date("Y-m-d H:i:s"),

                    // for some reason trying to set these 2 causes it to totally fail
                    // 'created' => $dateCreated ? strtotime($dateCreated) : time(), // Use `strtotime` to convert date strings to Unix timestamps
                    // 'updated' => time(),
                ];

                // $this->emDebug("Attempting to set data:", $data);
                if (!$entity->setData($data)) {
                    $this->emError("Set data failed:", $entity->getErrors());
                } else {
                    try {
                        if ($entity->save()) {
                            // $this->emDebug("Entity saved successfully.", $entity->getData());
                        } else {
                            $this->emError("Save failed.");
                        }
                    } catch (\Exception $e) {
                        $this->emError("Entity save exception: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                $this->emError("Failed to store document in Entity Table: " . $e->getMessage());
            }
        }
    }


    /**
     * Check for existing documents and store only if not already present.
     *
     * @param string $projectIdentifier
     * @param string $title
     * @param string $content
     * @param string|null $dateCreated
     */
    public function checkAndStoreDocument(string $projectIdentifier, string $title, string $content, ?string $dateCreated = null): void {
        $contentHash = $this->generateContentHash($content);

        if ($this->isVectorDbEnabled()) {
            $namespace = $projectIdentifier;
            $docId = $contentHash;

            // Check if vector exists
            try {
                $apiKey = $this->getSystemSetting('pinecone_api_key');
                $host   = rtrim($this->getSystemSetting('pinecone_host'), '/');

                $client = new \GuzzleHttp\Client();
                $resp = $client->post("$host/vectors/fetch", [
                    'headers' => [
                        'Api-Key' => $apiKey,
                        'Content-Type' => 'application/json'
                    ],
                    'json' => [
                        'namespace' => $namespace,
                        'ids' => [$docId]
                    ]
                ]);

                $body = json_decode($resp->getBody(), true);

                if (isset($body['vectors'][$docId])) {
                    $this->emDebug("Document already exists in Pinecone for {$namespace}. Skipping.");
                    return;
                }
            } catch (\Exception $e) {
                $this->emError("Pinecone fetch failed: " . $e->getMessage());
            }

            // Store if not found
            $this->storeDocument($projectIdentifier, $title, $content, $dateCreated);
            return;
        } else {
            try {
                $query = "SELECT id FROM redcap_entity_generic_contextdb
                      WHERE project_identifier = ?
                      AND hash = ?";
                $params = [$projectIdentifier, $contentHash];
                $result = $this->query($query, $params);

                if ($result->num_rows > 0) {
                    $this->emDebug("Document already exists in Entity Table for project {$projectIdentifier}. Skipping.");
                    return; // Skip storing duplicate
                }
            } catch (\Exception $e) {
                $this->emError("Failed to check document in Entity Table: " . $e->getMessage());
            }
        }

        // If no duplicates, store the document
        $this->storeDocument($projectIdentifier, $title, $content, $dateCreated);
    }

    private function generateContentHash($content) {
        return hash('sha256', $content);
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
     * Check if vector DB (Pinecone) is enabled in system settings.
     *
     * @return bool
     */
    private function isVectorDbEnabled(): bool
    {
        return (bool)$this->getSystemSetting('use_vectordb');
    }

    /**
     * Fallback to original TF-based sparse vector (keep as backup)
     */
    private function generateSparseVectorFallback(string $text): array {
        // Tokenize
        $words = preg_split('/\W+/u', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return ['indices' => [], 'values' => []];
        }

        // Term frequency map
        $freq = [];
        foreach ($words as $w) {
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }

        // Normalize weights
        $maxFreq = max($freq);

        $indices = [];
        $values  = [];

        foreach ($freq as $term => $count) {
            $weight = $count / $maxFreq;

            // hash → stable sparse index
            $idx = crc32($term) % 200000;

            $indices[] = $idx;
            $values[]  = $weight;
        }

        // Deduplicate collisions
        $combined = [];
        foreach ($indices as $i => $idx) {
            $val = $values[$i];
            $combined[$idx] = ($combined[$idx] ?? 0) + $val;
        }

        // Rebuild arrays
        $indices = array_keys($combined);
        $values  = array_values($combined);

        // Sort (required by Pinecone)
        array_multisort($indices, SORT_ASC, SORT_NUMERIC, $values);

        return [
            'indices' => $indices,
            'values'  => $values
        ];
    }

    /**
     * Generate sparse vector using Pinecone's pinecone-sparse-english-v0 model
     */
    private function generateSparseVector(string $text, string $inputType = 'query'): array
    {
        try {
            $apiKey = $this->getSystemSetting('pinecone_api_key');
            $embedHost = rtrim($this->getSystemSetting('pinecone_inference_host', 'https://api.pinecone.io'), '/');
            
            $client = new \GuzzleHttp\Client();
            $body = [
                'model' => 'pinecone-sparse-english-v0',
                'parameters' => [
                    'input_type' => $inputType,
                    'truncate' => 'END'
                ],
                'inputs' => [['text' => $text]]
            ];
            
            $resp = $client->post("$embedHost/embed", [
                'headers' => [
                    'Api-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'X-Pinecone-Api-Version' => '2025-10'  
                ],
                'json' => $body
            ]);
            
            $result = json_decode($resp->getBody(), true);
            $this->emDebug("Pinecone raw response: " . json_encode($result));
            
            // FIXED: Correct response structure
            if (isset($result['data'][0]['sparse_values']) && isset($result['data'][0]['sparse_indices'])) {
                $sparse = [
                    'indices' => $result['data'][0]['sparse_indices'],
                    'values' => array_map(fn($v) => min(1.0, max(0.0, $v)), $result['data'][0]['sparse_values'])  // Normalize 0-1
                ];
                
                // Sort for Pinecone
                array_multisort($sparse['indices'], SORT_ASC, SORT_NUMERIC, $sparse['values']);
                
                $this->emDebug("Generated {$inputType} sparse: " . count($sparse['indices']) . " indices");
                return $sparse;
            }
            
            $this->emError("Unexpected Pinecone sparse response: " . json_encode($result));
            return ['indices' => [], 'values' => []];
            
        } catch (Exception $e) {
            $this->emError("Pinecone sparse ({$inputType}) failed: " . $e->getMessage());
            return $this->generateSparseVectorFallback($text);
        }
    }


    private function pineconeSparseUpsert($namespace, $vectors) {
        $apiKey = $this->getSystemSetting('pinecone_api_key');
        $host   = rtrim($this->getSystemSetting('pinecone_host_sparse'), '/');

        $client = new \GuzzleHttp\Client();

        $resp = $client->post("$host/vectors/upsert", [
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'namespace' => $namespace,
                'vectors'   => $vectors
            ]
        ]);

        return json_decode($resp->getBody(), true);
    }

    private function pineconeSparseQuery($namespace, array $sparseVector, $topK = 20) {
        $apiKey = $this->getSystemSetting('pinecone_api_key');
        $host   = rtrim($this->getSystemSetting('pinecone_host_sparse'), '/');

        $client = new \GuzzleHttp\Client();
        $body = [
            'topK'            => $topK,
            'namespace'       => $namespace,
            'includeMetadata' => true,
            'sparseVector'    => $sparseVector,
        ];

        $resp = $client->post("$host/query", [
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => $body
        ]);

        return json_decode($resp->getBody(), true);
    }

    private function pineconeUpsert($namespace, $vectors) {
        $apiKey = $this->getSystemSetting('pinecone_api_key');
        $host   = rtrim($this->getSystemSetting('pinecone_host'), '/');

        $client = new \GuzzleHttp\Client();

        $resp = $client->post("$host/vectors/upsert", [
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'namespace' => $namespace,
                'vectors' => $vectors
            ]
        ]);

        return json_decode($resp->getBody(), true);
    }

    private function pineconeQuery($namespace, $embedding, $topK = 20) {
        $apiKey = $this->getSystemSetting('pinecone_api_key');
        $host   = rtrim($this->getSystemSetting('pinecone_host'), '/');

        $client = new \GuzzleHttp\Client();

        $body = [
            'topK'            => $topK,
            'includeMetadata' => true,
            'namespace'       => $namespace,
            'vector'          => $embedding,
        ];

        $resp = $client->post("$host/query", [
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => $body
        ]);

        return json_decode($resp->getBody(), true);
    }

    private function pineconeRequestCustomHost(string $host, string $path, array $payload, string $method = 'POST'): array {
        $apiKey = $this->getSystemSetting('pinecone_api_key');

        $client = new \GuzzleHttp\Client();
        $resp = $client->request($method, rtrim($host,'/').$path, [
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => $payload
        ]);

        return json_decode($resp->getBody(), true);
    }

    /**
     * Generic internal helper to call Pinecone JSON APIs.
     *
     * @param string $path
     * @param array  $payload
     * @param string $method
     * @return array
     * @throws \Exception
     */
    private function pineconeRequest(string $path, array $payload, string $method = 'POST', string $apiVersion = '2025-04'): array
    {
        $apiKey = $this->getSystemSetting('pinecone_api_key');
        $host   = rtrim((string)$this->getSystemSetting('pinecone_host'), '/');

        if (empty($apiKey) || empty($host)) {
            throw new \Exception("Pinecone API key or host is not configured.");
        }

        $client = new \GuzzleHttp\Client();
        $url    = $host . $path;

        $options = [
            'headers' => [
                'Api-Key'      => $apiKey,
                'Content-Type' => 'application/json',
                'X-Pinecone-Api-Version' => $apiVersion,
            ],
        ];

        if (!empty($payload)) {
            $options['json'] = $payload;
        }

        $response = $client->request($method, $url, $options);
        $body     = (string)$response->getBody();

        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to decode Pinecone response: " . json_last_error_msg());
        }

        return $decoded;
    }


    /**
     * List all context documents for a given project identifier / namespace.
     * In vector DB mode this lists Pinecone vectors; otherwise it lists entity rows.
     *
     * @param string $projectIdentifier
     * @param int    $limit
     * @return array
     */
    public function listContextDocuments(string $projectIdentifier, int $limit = 5000): array
    {
        $docs = [];

        if ($this->isVectorDbEnabled()) {
            try {
                $namespace = $projectIdentifier;

                // 1) Get namespace stats
                $payload = [ 'filter' => null ]; // optional
                $stats   = $this->pineconeRequest('/describe_index_stats', $payload, 'POST');

                if (!isset($stats['namespaces'][$namespace])) {
                    return [];
                }
                $count = $stats['namespaces'][$namespace]['vectorCount'] ?? 0;
                if ($count === 0) {
                    return [];
                }


                // 2) Use zero-vector query to pull items
                $fakeVector = array_fill(0, 1536, 0.0);
                $limit = min($limit, $count);

                $results = $this->pineconeRequest('/query', [
                    'namespace' => $namespace,
                    'topK'      => $limit,
                    'vector'    => $fakeVector,
                    'includeMetadata' => true
                ], 'POST');

                if (!isset($results['matches']) || empty($results['matches'])) {
                    return [];
                }

                foreach ($results['matches'] as $m) {
                    $meta = $m['metadata'] ?? [];
                    $docs[] = [
                        'id'      => $m['id'] ?? null,
                        'content' => $meta['content'] ?? '',
                        'source'  => $meta['source'] ?? '',
                        'meta_summary'  => $meta['meta_summary'] ?? null,
                        'meta_tags'     => $meta['meta_tags'] ?? null,
                        'meta_timestamp'=> $meta['timestamp'] ?? null,
                    ];
                }
            } catch (\Exception $e) {
                $this->emError("listContextDocuments Pinecone error: " . $e->getMessage());
            }

            return $docs;
        }

        // Entity / MySQL fallback
        try {
            $sql = "
                SELECT id, content, source, meta_summary, meta_tags, meta_timestamp
                FROM redcap_entity_generic_contextdb
                WHERE project_identifier = ?
                ORDER BY id DESC
                LIMIT ?
            ";
            $params = [$projectIdentifier, $limit];

            $result = $this->query($sql, $params);
            while ($row = db_fetch_assoc($result)) {
                $docs[] = [
                    'id'            => $row['id'],
                    'content'       => $row['content'],
                    'source'        => $row['source'],
                    'meta_summary'  => $row['meta_summary'],
                    'meta_tags'     => $row['meta_tags'],
                    'meta_timestamp'=> $row['meta_timestamp'],
                ];
            }
        } catch (\Exception $e) {
            $this->emError("listContextDocuments Entity error: " . $e->getMessage());
        }

        return $docs;
    }

    public function getPineconeNamespaces(){
        if ($this->isVectorDbEnabled()) {
            try {
                $namespaces = $this->pineconeRequest('/namespaces', [], 'GET');
                if(!empty($namespaces) && array_key_exists('namespaces', $namespaces)){
                    return $namespaces['namespaces'];
                }
            } catch (\Exception $e) {
                $this->emError("Error called attempting to fetch namespaces from Pinecone");
            }
        }
        return [];
    }

    public function listUnifiedContextDocs(string $namespace): array {
        $dense = $this->listPineconeNamespace($namespace, $this->getSystemSetting('pinecone_host'));
        $sparse = $this->listPineconeNamespace($namespace, $this->getSystemSetting('pinecone_host_sparse'));

        $map = [];

        foreach ($dense as $row) {
            $map[$row['id']] = [
                'id' => $row['id'],
                'content' => $row['content'],
                'source' => $row['source'],
                'dense_only' => true
            ];
        }

        foreach ($sparse as $row) {
            if (!isset($map[$row['id']])) {
                $map[$row['id']] = [
                    'id' => $row['id'],
                    'content' => $row['content'],
                    'source' => $row['source'],
                    'sparse_only' => true
                ];
            }
        }

        return array_values($map);
    }

    private function listPineconeNamespace(string $namespace, string $host): array {
        // Skip ALL serverless hosts (serverless cannot use describe_index_stats)
        if (preg_match('/\.svc\./i', $host) || preg_match('/gcp-.*\.pinecone\.io$/i', $host)) {
            $this->emDebug("Skipping serverless host (listing unsupported): $host");
            return [];
        }


        $stats = $this->pineconeRequestCustomHost($host, '/describe_index_stats', [], 'POST');

        if (!isset($stats['namespaces'][$namespace])) {
            return [];
        }

        $fakeVector = array_fill(0, 1536, 0.0);

        $resp = $this->pineconeRequestCustomHost($host, '/query', [
            'namespace' => $namespace,
            'topK' => 5000,
            'includeMetadata' => true,
            'vector' => $fakeVector
        ], 'POST');

        $rows = [];
        foreach ($resp['matches'] as $m) {
            $meta = $m['metadata'] ?? [];
            $rows[] = [
                'id' => $m['id'],
                'content' => $meta['content'] ?? '',
                'source' => $meta['source'] ?? ''
            ];
        }

        return $rows;
    }

    /**
     * Fetch a single context document by id for a given project / namespace.
     *
     * @param string $projectIdentifier
     * @param string $id
     * @return array|null
     */
    public function fetchContextDocument(string $projectIdentifier, string $id): ?array
    {
        if ($this->isVectorDbEnabled()) {
            try {
                $namespace = $projectIdentifier;
                $payload   = [
                    'namespace' => $namespace,
                    'ids'       => [$id],
                ];

                $body = $this->pineconeRequest('/vectors/fetch', $payload, 'POST');

                if (!isset($body['vectors'][$id])) {
                    return null;
                }

                $vec  = $body['vectors'][$id];
                $meta = $vec['metadata'] ?? [];

                return [
                    'id'            => (string)$id,
                    'content'       => $meta['content'] ?? '',
                    'source'        => $meta['source'] ?? '',
                    'meta_summary'  => $meta['meta_summary'] ?? null,
                    'meta_tags'     => $meta['meta_tags'] ?? null,
                    'meta_timestamp'=> $meta['timestamp'] ?? null,
                ];
            } catch (\Exception $e) {
                $this->emError("fetchContextDocument Pinecone error: " . $e->getMessage());
                return null;
            }
        }

        // Entity / MySQL fallback
        try {
            $sql = "
                SELECT id, content, source, meta_summary, meta_tags, meta_timestamp
                FROM redcap_entity_generic_contextdb
                WHERE project_identifier = ?
                  AND id = ?
                LIMIT 1
            ";
            $params = [$projectIdentifier, $id];

            $result = $this->query($sql, $params);
            if ($row = db_fetch_assoc($result)) {
                return [
                    'id'            => $row['id'],
                    'content'       => $row['content'],
                    'source'        => $row['source'],
                    'meta_summary'  => $row['meta_summary'],
                    'meta_tags'     => $row['meta_tags'],
                    'meta_timestamp'=> $row['meta_timestamp'],
                ];
            }
        } catch (\Exception $e) {
            $this->emError("fetchContextDocument Entity error: " . $e->getMessage());
        }

        return null;
    }


    /**
     * Delete a single context document by id.
     *
     * @param string $projectIdentifier
     * @param string $id
     * @return bool
     */
    public function deleteContextDocument(string $projectIdentifier, string $id): bool
    {
        if ($this->isVectorDbEnabled()) {
            $namespace = $projectIdentifier;

            // Payload is same for both
            $payload = [
                'namespace' => $namespace,
                'ids'       => [$id],
            ];

            try {
                // Delete from dense index
                if ($host = $this->getSystemSetting('pinecone_host')) {
                    $this->pineconeRequestCustomHost($host, '/vectors/delete', $payload, 'POST');
                }

                // Delete from sparse index
                if ($hostSparse = $this->getSystemSetting('pinecone_host_sparse')) {
                    $this->pineconeRequestCustomHost($hostSparse, '/vectors/delete', $payload, 'POST');
                }

                return true;

            } catch (\Exception $e) {
                $this->emError("deleteContextDocument Pinecone hybrid error: " . $e->getMessage());
                return false;
            }
        }

        // Entity / MySQL fallback
        try {
            $sql    = "DELETE FROM redcap_entity_generic_contextdb WHERE project_identifier = ? AND id = ?";
            $params = [$projectIdentifier, $id];
            $this->query($sql, $params);
            return true;
        } catch (\Exception $e) {
            $this->emError("deleteContextDocument Entity error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge all context documents for a given project/namespace.
     *
     * @param string $projectIdentifier
     * @return bool
     */
    public function purgeContextNamespace(string $projectIdentifier): bool
    {
        if ($this->isVectorDbEnabled()) {
            try {
                $namespace = $projectIdentifier;
                $payload = [
                    'namespace' => $namespace,
                    'deleteAll' => true,
                ];

                // Dense host
                $denseHost = $this->getSystemSetting('pinecone_host');
                if ($denseHost) {
                    $this->pineconeRequestCustomHost($denseHost, '/vectors/delete', $payload, 'POST');
                }

                // Sparse host
                $sparseHost = $this->getSystemSetting('pinecone_host_sparse');
                if ($sparseHost) {
                    $this->pineconeRequestCustomHost($sparseHost, '/vectors/delete', $payload, 'POST');
                }

                return true;
            } catch (\Exception $e) {
                $this->emError("purgeContextNamespace error: " . $e->getMessage());
                return false;
            }
        }

        // MySQL fallback
        try {
            $sql = "DELETE FROM redcap_entity_generic_contextdb
                    WHERE project_identifier = ?";
            $this->query($sql, [$projectIdentifier]);
            return true;
        } catch (\Exception $e) {
            $this->emError("purgeContextNamespace Entity error: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Simple debug search over context documents using the existing embedding model.
     * This is a lighter-weight version of getRelevantDocuments for a plain text query.
     *
     * @param string $projectIdentifier
     * @param string $query
     * @param int    $topK
     * @return array
     */
    public function debugSearchContext(string $projectIdentifier, string $query, int $topK = 3): array
    {
        $queryEmbedding = $this->getEmbedding($query);
        if (!$queryEmbedding) {
            return [];
        }

        $sparseQuery = $this->generateSparseVector($query);

        // If vector DB is enabled, reuse Pinecone query
        if ($this->isVectorDbEnabled()) {
            try {
                $namespace = $projectIdentifier;
                $candidateK = intval($this->getSystemSetting('hybrid_candidate_k') ?? 20);

                // --- Dense search ---
                $denseResults = [];
                try {
                    $denseResults = $this->pineconeQuery($namespace, $queryEmbedding, $candidateK);
                } catch (\Exception $e) {
                    $this->emError("debug dense query failed: ".$e->getMessage());
                }

                // --- Sparse search ---
                $sparseResults = [];
                try {
                    $sparseResults = $this->pineconeSparseQuery($namespace, $sparseQuery, $candidateK);
                } catch (\Exception $e) {
                    $this->emError("debug sparse query failed: ".$e->getMessage());
                }


                // $this->emDebug("denseResults", $denseResults);
                // $this->emDebug("sparseResults", $sparseResults);

                // --- Merge ---
                $merged = $this->mergeHybridResults($denseResults, $sparseResults, $candidateK);
                // Slice to final topK for RAG / caller
                $merged = array_slice($merged, 0, $topK);

                $documents = [];
                foreach ($merged as $m) {
                    $meta = $m['metadata'] ?? [];
                    $documents[] = [
                        'id'            => $m['id'] ?? null,
                        'content'       => $meta['content'] ?? '',
                        'source'        => $meta['source'] ?? '',
                        'meta_summary'  => $meta['meta_summary'] ?? null,
                        'meta_tags'     => $meta['meta_tags'] ?? null,
                        'meta_timestamp'=> $meta['timestamp'] ?? null,
                        'dense'         => $m['dense_score']  ?? null,
                        'sparse'        => $m['sparse_score'] ?? null,
                        'similarity'    => $m['hybrid_score'] ?? 0,
                    ];
                }

                return $documents;

            } catch (\Exception $e) {
                $this->emError("debugSearchContext Pinecone error: ".$e->getMessage());
                return [];
            }
        }


        // Entity / MySQL fallback: cosine similarity over all rows
        $documents = [];
        try {
            $sql = "
                SELECT id
                FROM redcap_entity_generic_contextdb
                WHERE project_identifier = ?
            ";
            $params = [$projectIdentifier];
            $result = $this->query($sql, $params);

            $entityFactory = $this->getEntityFactory();
            while ($row = db_fetch_assoc($result)) {
                $entity       = $entityFactory->getInstance('generic_contextdb', $row['id']);
                $data         = $entity->getData();
                $docEmbedding = json_decode($data['vector_embedding'], true);

                if (!is_array($docEmbedding)) {
                    continue;
                }

                $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

                $documents[] = [
                    'id'            => $entity->getId(),
                    'content'       => $data['content'],
                    'source'        => $data['source'],
                    'meta_summary'  => $data['meta_summary'],
                    'meta_tags'     => $data['meta_tags'],
                    'meta_timestamp'=> $data['meta_timestamp'],
                    'similarity'    => $similarity,
                ];
            }
        } catch (\Exception $e) {
            $this->emError("debugSearchContext Entity error: " . $e->getMessage());
            return [];
        }

        usort($documents, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($documents, 0, $topK);
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
