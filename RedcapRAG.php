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

    private $entityFactory;

    public function __construct() {
        parent::__construct();
//        $this->entityFactory = new \REDCapEntity\EntityFactory();
    }

    /**
     * Define entity types for the module.
     *
     * @return array $types
     */
    public function redcap_entity_types() {
        $types = [];

        // Define entity structure for chatbot_contextdb
        $types['chatbot_contextdb'] = [
            'label' => 'Chatbot Context',
            'label_plural' => 'Chatbot Contexts',
            'icon' => 'file',
            'properties' => [
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
        ];

        return $types;
    }

    /**
     * Trigger schema build for the entity when the module is enabled.
     *
     * @param string $version
     * @return void
     */
    public function redcap_module_system_enable($version) {
//        \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
    }

    /**
     * Retrieve the embedding for the given text using SecureChat AI.
     *
     * @param string $text
     * @return array|null
     * @throws GuzzleException
     */
    private function getEmbedding($text) {
        try {
            $result = $this->getSecureChatInstance()->callAI("ada-002", array("input" => $text));
            return $result['data'][0]['embedding'];
        } catch (GuzzleException $e) {
            $this->emError("Guzzle error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the most relevant documents from the chatbot context based on the query.
     *
     * @param array $queryArray
     * @return array|null
     */
    public function getRelevantDocuments($queryArray) {
        // Stubbed fake return slice of array
        $largeText_1 = "R2P2 - Linking REDCap to your R2P2 Project with the R2P2 Dashboard The following wiki entry is a work in progress. Please provide feedback and/or suggest below any questions you need answers to and we will try to improve the documentation. DESCRIPTION Stanford REDCap constructed the R2P2 Dashboard within each REDCap project in order to integrate processes and information requests between the two systems. This integration of REDCap and R2P2 allows you to: Link your REDCap project directly to an R2P2 project Generate or manage a REDCap Maintenance Agreement (RMA) Create, view, and manage support tickets View accrued monthly maintenance fees for External Modules (EMs) Request a Professional Services support block CONTENTS Finding the R2P2 Dashboard in your REDCap Project The REDCap R2P2 Dashboard button can be found in the Help & Information section of your REDCap project's left-hand menu: Linking your REDCap project to R2P2 Linking to an R2P2 project is done through the R2P2 tab within the REDCap R2P2 Dashboard. If your protocol team has an existing R2P2 project, search for it in the drop-down menu and click 'Attach Selected Project' to establish a link to your REDCap project If your protocol team does not have an R2P2 project (or if you're uncertain that an R2P2 project exists), click the 'Find or Create a R2P2 Project' button (circled in maroon in the screenshot) Once the link to an R2P2 project is established, it's time to create an RMA. Generating and managing an RMA The REDCap Maintenance Agreement (RMA) is a special Statement of Work that authorizes monthly maintenance fees for certain RIT/TDS charges in REDCap. Please see the RMA wiki entry for details. NOTE: RMA's are required for Production REDCap Projects using External Modules with recurring monthly fees, as of January 1st, 2022. Generating an RMA If you need to generate an RMA through the R2P2 dashboard, initiate the process by clicking on Step 1: Generate a REDCap Maintenance Agreement button and follow all prompts Once the RMA is generated and awaiting approval, follow the prompts to approve the RMA by clicking the 'Approve REDCap Maintenance Agreement in R2P2' Existing RMAs If the R2P2 project you're linking to has an existing RMA, follow the steps below to link your REDCap project: Confirmation of Linked RMA Approved RMA status appears in the REDCap R2P2 dash thusly: Support tickets Creating and managing support tickets through the REDCap R2P2 dashboard is an easy way to tie together all pertinent pieces of information (REDCap project, your R2P2 project number, your question and/or issue needing resolution, your name) needed to facilitate faster trouble-shooting. Support tickets created through the dashboard give the Stanford REDCap team all the pertinent information we need to find your project faster, answer questions, and solve any issues. Creating a Ticket Create a new support ticket by clicking 'Add Ticket' Follow the prompts to create a new ticket. Click 'Submit' to send it to the Research IT support queue. A submitted ticket appears in the dashboard with the status of 'Waiting for support' When work on the ticket has wrapped up, the status will change to 'Resolved' Enabled External Modules The Enabled External Modules tab displays all enabled external modules–and any associated monthly maintenance fees with total cost. If you see an external module (especially one that has a monthly maintenance fee) and are unsure what it is - you can view the REDCap External Module report for more information or: Create a Support Ticket for more information about the module, or Schedule an Office Hour visit to speak directly with a member of the REDCap admin team NOTE: We do not recommend disabling a module in a production project until you have verified that it isn't being used as it could otherwise affect the project. That said, deactivating external modules when you are finished using them is entirely appropriate. If you need/want any guidance in deactivating a module, please contact REDCap support. Professional Services The REDCap R2P2 dashboard facilitates a rapid request process for generating a support block. This process is outlined in the wiki Rapid Request a Support Block";
        $largeText_2 = "TABLEU EXPORT DESCRIPTION In July 2021, REDCap added core functionality that facilitates exporting REDCap data directly into Tableau Desktop via API. Exports are set up via the Tableau Connector, found in your REDCap project's Other Export Options: Setting up the connector is straightforward and easy–but REDCap users need two things prior to setting up the connector: An API token for the REDCap project you're extracting data from, and Tableau Desktop v10 or higher CONTENTS Configuring the connector Click the blue 'View export instructions' button to access the connector configuration instructions: Follow the instructions as outlined in the pop-up window: Tableau APIs, Developer Tools Tableau is constantly expanding its range of API options and developer tools--visit the Tableau Developer Tools page. If your data visualization needs are complex or complicated and you would like the help of a REDCap expert to assist with setting up data feeds, request a Professional Services consultation via R2P2. Need help with Tableau? If you're new to Tableau–or if you're new to configuring and using Tableau's APIs–a wealth of Tableau resources exist to help out. Free resources Tableau Community Tableau Public – Tableau's free, limited-scope and limited-use platform to learn data viz basics. Tableau public grants access to: Sample data for experimentation Video tutorials Coursera, Trailhead classes Podcasts Social media Tableau user groups at your health institution Stanford UIT Courses Data Analysis and Visualization on Tableau Server – for end users Introduction to Tableau Desktop – for developers Tableau Desktop - Beginner (4 day class)";
        $largeText_3 = "Calendar Application and Scheduling Module DESCRIPTION The Calendar application in REDCap can be used to schedule study-related events/appointments/visits individually, or in bulk derived from pre-defined data collection events configured within your REDCap project. The REDCap Calendar application has the capability to sync with external calendars (i.e. Google Calendar, Outlook, etc) that support ICS or iCal file formats. CONTENTS Manually Scheduling a Calendar Event/Appointment Creating individual appointments is possible using the calendar application interface. Click 'Calendar' in the left-hand Applications menu: Click the +New link in the upper left-hand corner of the date you want to schedule an event/appointment on Enter event/appointment details: Time: REDCap uses the 24-hour clock Notes (optional) Record ID: Select an existing record from the drop-down menu if scheduling for an existing participant, or leave the Record ID field empty to generate an appointment for an as-yet-unassigned Record ID When finished, click 'Add Calendar Event' to save the event/appointment to the REDCap Calendar: Scheduling Module If longitudinal data collection is enabled in your project, it's possible to harness pre-defined data collection events to schedule series of calendar events/appointments for a given participant. Enabling REDCap's Scheduling Module is necessary to accomplish this. Navigate to Project Setup > Enable optional modules and customizations > click the 'Enable' button to the left of Scheduling module (longitudinal only) For the purpose of this demonstration, the steps outlined below are based on this sample data collection event schedule: To create a schedule using the Scheduling Module, navigate to the Scheduling Generator. Open the Scheduling module in the left-hand Data Collection menu: Follow the prompts in the Create Schedule tab to create a series of events/appointments for an existing record or for a new record: Click 'Generate Schedule' to review the projected schedule for your record, making individual edits as needed (i.e. adding appointment times, adjusting the day of the week on which a given appointment falls, etc): Click 'Create Schedule' to add the events/appointments to the REDCap calendar If you need to edit, view, or manually add an ad hoc event to an existing event/appointment series, navigate to the View or Edit Schedule tab. Any date modifications you make in this tab will apply only to this specific event/appointment series, and will not modify the project-level settings for your data collection events. As a feature, the Scheduling Module links data collection instruments designated to the corresponding data collection event within the REDCap Calendar event/appointment. In the example below, the Month 1 data collection event contains 6 instruments that may be completed at the Month 1 visit: Syncing to external calendars It's possible to sync a REDCap calendar to external calendar applications that support iCal or ICS file formats (i.e. Google Calendar, Outlook, Apple Calendar, etc). Sync times/frequencies will vary, based on your calendar service of choice. NOTE: if syncing with an external calendar, ensure that HIPAA identifiers do not appear in the calendar appointment To sync with an external calendar: Open the REDCap Calendar Click 'Sync Calendar to External Application' Follow the prompts in Live calendar feed: Add Calendar from URL/Internet Follow the instructions in your particular calendar of choice to add the URL as a new calendar Limitations and Constraints As of REDCap v12.5.7, the following items cannot be used directly in conjunction with the Calendar and Scheduling Module: Action tags Smart variables Date fields within a data collection instrument, including calculated dates Alerts & Notifications If you are interested in sending Alerts & Notifications to participants based on specific study event dates, one suggestion is to create anticipated visit date fields within your REDCap project using the CALCDATE function. The resulting dates can be used when configuring an alert's schedule. Here's one example of anticipated future visit date fields: The basic equation used for each of the subsequent visit dates is: @CALCDATE([ddemo_startdate],30,'d') @CALCDATE([ddemo_startdate],90,'d') @CALCDATE([ddemo_startdate],180,'d')";

//        return [
//            [
//                'id' => 101,
//                'content' => $largeText_1,
//                'source' => 'example_source_101',
//                'meta_summary' => 'summary of entity 101',
//                'meta_tags' => ['tag1', 'tag2'],
//                'meta_timestamp' => '2023-10-01T12:34:56Z',
//                'similarity' => 0.95
//            ],
//            [
//                'id' => 102,
//                'content' => $largeText_2,
//                'source' => 'example_source_102',
//                'meta_summary' => 'summary of entity 102',
//                'meta_tags' => ['tag3', 'tag4'],
//                'meta_timestamp' => '2023-10-02T12:34:56Z',
//                'similarity' => 0.90
//            ],
//            [
//                'id' => 103,
//                'content' => $largeText_3,
//                'source' => 'example_source_103',
//                'meta_summary' => 'summary of entity 103',
//                'meta_tags' => ['tag5', 'tag6'],
//                'meta_timestamp' => '2023-10-03T12:34:56Z',
//                'similarity' => 0.85
//            ]
//        ];

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

        // Use Redis instead of Entity Table (Pseudo-code)
        // Pseudo-code for Redis:
        /*
        $redis = new Redis();
        $redis->connect('redis-server', 6379);
        $documents = $redis->zrangebyscore('chatbot_contexts', 'min', 'max'); // Redis Search logic
        */

        // If Redis fails or is unavailable, fallback to entity table
        $entities = $this->entityFactory->loadInstances('chatbot_contextdb', $this->getAllEntityIds('chatbot_contextdb'));
        $documents = [];

        foreach ($entities as $entity) {
            $docEmbedding = json_decode($entity->getData()['vector_embedding'], true);
            $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

            $documents[] = [
                'id' => $entity->getId(),
                'content' => $entity->getData()['content'],
                'source' => $entity->getData()['source'],
                'meta_summary' => $entity->getData()['meta_summary'],
                'meta_tags' => $entity->getData()['meta_tags'],
                'meta_timestamp' => $entity->getData()['meta_timestamp'],
                'similarity' => $similarity
            ];
        }

        // Sort by similarity
        usort($documents, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($documents, 0, 3);
    }

    /**
     * Store a new document with its embedding vector in the database.
     *
     * @param string $title
     * @param string $content
     * @return void
     */
    public function storeDocument($title, $content) {
        $embedding = $this->getEmbedding($content);
        $serialized_embedding = json_encode($embedding);

        // Store in Redis or Entity Table (pseudo-code for Redis)
        /*
        $redis->zadd('chatbot_contexts', [$serialized_embedding, $title, $content]);
        */

        // If Redis fails, fallback to entity table
        $entity = new \REDCapEntity\Entity($this->entityFactory, 'chatbot_contextdb');

        $entity->setData([
            'content' => $content,
            'content_type' => 'text',
            'file_url' => null,
            'upvotes' => random_int(0, 100),
            'downvotes' => random_int(0, 50),
            'source' => $title,
            'vector_embedding' => $serialized_embedding,
            'meta_summary' => 'A strategic quote from Sun Tzu',
            'meta_tags' => 'tag1, tag2, tag3',
            'meta_timestamp' => date("Y-m-d H:i:s"),
        ]);

        $entity->save();
    }

    /**
     * Fetch all entity IDs of a given type.
     *
     * @param string $entityType
     * @return array
     */
    private function getAllEntityIds($entityType) {
        $ids = [];
        $sql = 'SELECT id FROM `redcap_entity_' . db_escape($entityType) . '`';
        $result = db_query($sql);

        while ($row = db_fetch_assoc($result)) {
            $ids[] = $row['id'];
        }

        return $ids;
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
            return 0; // Return zero if either vector norm is zero
        }

        return $dotProduct / ($normVec1 * $normVec2);
    }

    /**
     * Get the SecureChatAI instance from the module.
     *
     * @return \Stanford\SecureChatAI\SecureChatAI
     * @throws \Exception
     */
    public function getSecureChatInstance(): \Stanford\SecureChatAI\SecureChatAI
    {
        if(empty($this->secureChatInstance)){
            $this->setSecureChatInstance(\ExternalModules\ExternalModules::getModuleInstance(self::SecureChatInstanceModuleName));
            return $this->secureChatInstance;
        }else{
            return $this->secureChatInstance;
        }
    }

    /**
     * Set the SecureChatAI instance.
     *
     * @param \Stanford\SecureChatAI\SecureChatAI $secureChatInstance
     */
    public function setSecureChatInstance(\Stanford\SecureChatAI\SecureChatAI $secureChatInstance): void
    {
        $this->secureChatInstance = $secureChatInstance;
    }
}
?>
