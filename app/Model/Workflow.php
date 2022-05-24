<?php
App::uses('AppModel', 'Model');
App::uses('WorkflowGraphTool', 'Tools');

class Workflow extends AppModel
{
    public $recursive = -1;

    public $actsAs = [
        'AuditLog',
        'Containable',
    ];

    public $belongsTo = [
        'User' => [
            'className' => 'User',
            'foreignKey' => 'user_id',
        ],
        'Organisation' => [
            'className' => 'Organisation',
            'foreignKey' => 'org_id'
        ]
    ];

    public $validate = [
        'value' => [
            'stringNotEmpty' => [
                'rule' => ['stringNotEmpty']
            ]
        ],
        'uuid' => [
            'uuid' => [
                'rule' => 'uuid',
                'message' => 'Please provide a valid RFC 4122 UUID'
            ],
            'unique' => [
                'rule' => 'isUnique',
                'message' => 'The UUID provided is not unique',
                'required' => 'create'
            ]
        ],
        'data' => [
            'rule' => ['hasAcyclicGraph'],
            'message' => 'Cannot save a workflow containing a cycle',
            // TODO: Force only one type of trigger module per WF
        ]
    ];

    public $defaultContain = [
        // 'Organisation',
        // 'User'
    ];

    public $loaded_modules = [];
    public $loaded_classes = [];

    const CAPTURE_FIELDS = ['name', 'description', 'timestamp', 'data'];

    const MODULE_ROOT_PATH = APP . 'Model/WorkflowModules/';
    const REDIS_KEY_WORKFLOW_NAMESPACE = 'workflow';
    const REDIS_KEY_WORKFLOW_PER_TRIGGER = 'workflow:workflow_list:%s';
    const REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER = 'workflow:workflow_blocking_order_list:%s';
    const REDIS_KEY_TRIGGER_PER_WORKFLOW = 'workflow:trigger_list:%s';

    private $moduleByID = [];

    public function __construct($id = false, $table = null, $ds = null)
    {
        parent::__construct($id, $table, $ds);
        $this->workflowGraphTool = new WorkflowGraphTool();
        $this->loadAllWorkflowModules();
    }

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        if (empty($this->data['Workflow']['data'])) {
            $this->data['Workflow']['data'] = [];
        }
        if (empty($this->data['Workflow']['timestamp'])) {
            $this->data['Workflow']['timestamp'] = time();
        }
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (empty($result['Workflow']['data'])) {
                $result['Workflow']['data'] = '{}';
            }
            $results[$k]['Workflow']['data'] = JsonTool::decode($result['Workflow']['data']);
            if (!empty($result['Workflow']['id'])) {
                $trigger_ids = $this->getTriggersIDPerWorkflow((int) $result['Workflow']['id']);
                $results[$k]['Workflow']['listening_triggers'] = $this->getModuleByID($trigger_ids);
            }
        }
        return $results;
    }

    public function beforeSave($options = [])
    {
        $this->data['Workflow']['data'] = JsonTool::encode($this->data['Workflow']['data']);
        return true;
    }

    public function afterSave($created, $options = [])
    {
        $this->updateListeningTriggers($this->data);
    }

    public function rebuildRedis($user)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        $workflows = $this->fetchWorkflows($user);
        $keys = $redis->keys(Workflow::REDIS_KEY_WORKFLOW_NAMESPACE . ':*');
        $redis->delete($keys);
        foreach ($workflows as $wokflow) {
            $this->updateListeningTriggers($wokflow);
        }
    }

    /**
     * updateListeningTriggers 
     *  - Update the list of triggers that will be run this workflow
     *  - Update the list of workflows that are run by their triggers
     *  - Update the ordered list of workflows that are run by their triggers
     *
     * @param  array $workflow
     */
    public function updateListeningTriggers($workflow)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        if (!is_array($workflow['Workflow']['data'])) {
            $workflow['Workflow']['data'] = JsonTool::decode($workflow['Workflow']['data']);
        }
        $original_trigger_list_id = $this->getTriggersIDPerWorkflow((int)$workflow['Workflow']['id']);
        $new_node_trigger_list = $this->workflowGraphTool->extractTriggersFromWorkflow($workflow['Workflow'], true);
        $new_node_trigger_list_per_id = Hash::combine($new_node_trigger_list, '{n}.data.id', '{n}');
        $new_trigger_list_id = array_keys($new_node_trigger_list_per_id);
        $trigger_to_remove = array_diff($original_trigger_list_id, $new_trigger_list_id);
        $trigger_to_add = array_diff($new_trigger_list_id, $original_trigger_list_id);
        $pipeline = $redis->multi();
        foreach ($trigger_to_remove as $trigger_id) {
            $pipeline->sRem(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
            $pipeline->sRem(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow['Workflow']['id']), $trigger_id);
            $pipeline->lRem(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), $workflow['Workflow']['id'], 0);
        }
        $pipeline->exec();
        $pipeline = $redis->multi();
        foreach ($trigger_to_add as $trigger_id) {
            if (
                $this->workflowGraphTool->triggerHasNonBlockingPath($new_node_trigger_list_per_id[$trigger_id])
                || $this->workflowGraphTool->triggerHasBlockingPath($new_node_trigger_list_per_id[$trigger_id])
            ) {
                $pipeline->sAdd(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
                $pipeline->sAdd(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow['Workflow']['id']), $trigger_id);
                if ($this->workflowGraphTool->triggerHasBlockingPath($new_node_trigger_list_per_id[$trigger_id])) {
                    $pipeline->rPush(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
                }
            }
        }
        $pipeline->exec();
    }

    /**
     * getWorkflowsIDPerTrigger Get list of workflow IDs listening to the specified trigger
     *
     * @param  string $trigger_id
     * @return array
     */
    private function getWorkflowsIDPerTrigger($trigger_id): array
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        $list = $redis->sMembers(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id));
        return !empty($list) ? $list : [];
    }

    /**
     * getOrderedWorkflowsPerTrigger Get list of workflow IDs in the execution order for the specified trigger
     *
     * @param  string $trigger_id
     * @return bool|array
     */
    private function getOrderedWorkflowsPerTrigger($trigger_id)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        return $redis->lRange(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), 0, -1);
    }

    /**
     * getTriggersIDPerWorkflow Get list of trigger name running to the specified workflow
     *
     * @param  int $workflow_id
     * @return bool|array
     */
    private function getTriggersIDPerWorkflow(int $workflow_id)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        return $redis->sMembers(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow_id));
    }

    /**
     * saveBlockingWorkflowExecutionOrder Get list of trigger name running to the specified workflow
     *
     * @param  string $trigger_id
     * @param  array $workflows List of workflow IDs in priority order
     * @return bool
     */
    public function saveBlockingWorkflowExecutionOrder($trigger_id, array $workflow_order): bool
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        $key = sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id);
        $pipeline = $redis->multi();
        $pipeline->del($key);
        foreach ($workflow_order as $workflow_id) {
            $pipeline->rpush($key, (string)$workflow_id);
        }
        $pipeline->exec();
        return true;
    }

    /**
     * buildACLConditions Generate ACL conditions for viewing the workflow
     *
     * @param  array $user
     * @return array
     */
    public function buildACLConditions(array $user)
    {
        $conditions = [];
        if (!$user['Role']['perm_site_admin']) {
            $conditions['Workflow.org_id'] = $user['org_id'];
        }
        return $conditions;
    }

    public function canEdit(array $user, array $workflow)
    {
        if ($user['Role']['perm_site_admin']) {
            return true;
        }
        if (empty($workflow['Workflow'])) {
            return __('Could not find associated workflow');
        }
        if ($workflow['Workflow']['user_id'] != $user['id']) {
            return __('Only the creator user of the workflow can modify it');
        }
        return true;
    }

    private function loadModuleByID()
    {
        if (empty($this->moduleByID)) {
            $modules = $this->getModules();
            foreach ($modules as $module) {
                $this->moduleByID[$module['id']] = $module;
            }
        }
    }

    /**
     * attachWorkflowsToTriggers Collect the workflows listening to this trigger
     *
     * @param  array $user
     * @param  array $triggers
     * @param  bool $group_per_blocking Whether or not the workflows should be grouped together if they have a blocking path set
     * @return array
     */
    public function attachWorkflowsToTriggers(array $user, array $triggers, bool $group_per_blocking=true): array
    {
        $all_workflow_ids = [];
        $workflows_per_trigger = [];
        $ordered_workflows_per_trigger = [];
        foreach ($triggers as $trigger) {
            $workflow_ids_for_trigger = $this->getWorkflowsIDPerTrigger($trigger['id']);
            $workflows_per_trigger[$trigger['id']] = $workflow_ids_for_trigger;
            $ordered_workflows_per_trigger[$trigger['id']] = $this->getOrderedWorkflowsPerTrigger($trigger['id']);
            foreach ($workflow_ids_for_trigger as $id) {
                $all_workflow_ids[$id] = true;
            }
        }
        $all_workflow_ids = array_keys($all_workflow_ids);
        $workflows = $this->fetchWorkflows($user, [
            'conditions' => [
                'Workflow.id' => $all_workflow_ids,
            ],
            'fields' => ['*'],
            'contain' => ['Organisation' => ['fields' => ['*']]],
        ]);
        $workflows = Hash::combine($workflows, '{n}.Workflow.id', '{n}');
        foreach ($triggers as $i => $trigger) {
            $workflow_ids = $workflows_per_trigger[$trigger['id']];
            $ordered_workflow_ids = $ordered_workflows_per_trigger[$trigger['id']];
            $triggers[$i]['Workflows'] = [];
            foreach ($workflow_ids as $workflow_id) {
                $triggers[$i]['Workflows'][] = $workflows[$workflow_id];
            }
            if (!empty($group_per_blocking)) {
                $triggers[$i]['GroupedWorkflows'] = $this->groupWorkflowsPerBlockingType($triggers[$i]['Workflows'], $trigger['id'], $ordered_workflow_ids);
            }
        }
        return $triggers;
    }

    public function fetchWorkflowsForTrigger($user, $trigger_id): array
    {
        $workflow_ids_for_trigger = $this->getWorkflowsIDPerTrigger($trigger_id);
        $workflows = $this->fetchWorkflows($user, [
            'conditions' => [
                'Workflow.id' => $workflow_ids_for_trigger,
            ],
            'fields' => ['*'],
            'contain' => ['Organisation' => ['fields' => ['*']]],
        ]);
        return $workflows;
    }

    /**
     * getExecutionOrderForTrigger Generate the e
     *
     * @param  array $user
     * @param  array $trigger
     * @return array
     */
    public function getExecutionOrderForTrigger(array $user, array $trigger): array
    {
        if (empty($trigger)) {
            return ['blocking' => [], 'non-blocking' => [] ];
        }
        $workflows = $this->fetchWorkflowsForTrigger($user, $trigger['id']);
        $ordered_workflow_ids = $this->getOrderedWorkflowsPerTrigger($trigger['id']);
        return $this->groupWorkflowsPerBlockingType($workflows, $trigger['id'], $ordered_workflow_ids);
    }

    /**
     * groupWorkflowsPerBlockingType Group workflows together if they have a blocking path set or not. Also, sort the blocking list based on execution order
     *
     * @param  array $workflows
     * @param  string $trigger_id The trigger for which we should decide if it's blocking or not
     * @param  array $ordered_workflow_ids If provided, will sort the blocking workflows based on the workflow_id order in of the provided list
     * @return array
     */
    public function groupWorkflowsPerBlockingType(array $workflows, $trigger_id, $ordered_workflow_ids=false): array
    {
        $groupedWorkflows = [
            'blocking' => [],
            'non-blocking' => [],
        ];
        foreach ($workflows as $workflow) {
            foreach ($workflow['Workflow']['data'] as $block) {
                if ($block['data']['id'] == $trigger_id) {
                    if ($this->workflowGraphTool->triggerHasBlockingPath($block)) {
                        $order_index = array_search($workflow['Workflow']['id'], $ordered_workflow_ids);
                        $groupedWorkflows['blocking'][$order_index] = $workflow;
                    }
                    if ($this->workflowGraphTool->triggerHasNonBlockingPath($block)) {
                        $groupedWorkflows['non-blocking'][] = $workflow;
                    }
                }
            }
        }
        ksort($groupedWorkflows['blocking']);
        return $groupedWorkflows;
    }

    /**
     * isGraphAcyclic Return if the graph is acyclic or not
     *
     * @param array $graphData
     * @return boolean
     */
    public function hasAcyclicGraph(array $workflow): bool
    {
        $graphData = !empty($workflow['Workflow']) ? $workflow['Workflow']['data'] : $workflow['data'];
        $cycles = [];
        $isAcyclic = $this->workflowGraphTool->isAcyclic($graphData, $cycles);
        return $isAcyclic;
    }

    /**
     * navigateGraph Explore the graph and execute each nodes
     *
     * @param array $graphData
     * @return boolean
     */
    public function navigateGraph(array $workflow)
    {
        $graphData = !empty($workflow['Workflow']) ? $workflow['Workflow']['data'] : $workflow['data'];
        $navigator = $this->workflowGraphTool->getNavigatorIterator($graphData, 'publish');
        foreach ($navigator as $graphNode) {
            $node = $graphNode['node'];
            $path_type = $graphNode['path_type'];
            $moduleClass = $this->getModuleClass($node);
            if (!is_null($moduleClass)) {
                try {
                    $moduleClass->exec($node);
                } catch (Exception $e) {
                    $message = sprintf(__('Error while executing module: %s'), $e->getMessage());
                    $this->__logLoadingError($node['data']['id'], $message);
                }
            }
        }
    }

    public function getModuleClass($node)
    {
        $moduleClass = $this->loaded_classes[$node['data']['module_type']][$node['data']['id']] ?? null;
        return $moduleClass;
    }

    public function attachNotificationToModules(array $user, array $modules, array $workflow): array
    {
        foreach ($modules as $moduleType => $modulesByType) {
            foreach ($modulesByType as $i => $module) {
                $modules[$moduleType][$i]['notifications'] = !empty($module['notifications']) ? $module['notifications'] : [
                    'error' => [],
                    'warning' => [],
                    'info' => [],
                ];
            }
        }
        $triggers = $modules['blocks_trigger'];
        foreach ($triggers as $i => $trigger) {
            $blockingExecutionOrder = $this->getExecutionOrderForTrigger($user, $trigger)['blocking'];
            $blockingExecutionOrder = array_filter($blockingExecutionOrder, function($workflow) {
                return $workflow['Workflow']['enabled'];
            });
            $blockingExecutionOrderIDs = Hash::extract($blockingExecutionOrder, '{n}.Workflow.id');
            $indexInExecutionPath = array_search($workflow['Workflow']['id'], $blockingExecutionOrderIDs);
            $effectiveBlockingExecutionOrder = array_slice($blockingExecutionOrder, 0, $indexInExecutionPath);
            $details = [];
            foreach ($effectiveBlockingExecutionOrder as $workflow) {
                $details[] = sprintf('[%s] %s', h($workflow['Workflow']['id']), h($workflow['Workflow']['name']));
            }
            if (!empty($effectiveBlockingExecutionOrder)) {
                $modules['blocks_trigger'][$i]['notifications']['warning'][] = [
                    'text' => __('%s blocking worflows are executed before this trigger.', count($effectiveBlockingExecutionOrder)),
                    'description' => __('The blocking path of this trigger might not be executed. If any of the blocking workflows stop the propagation, the blocking path of this trigger will not be executed. Nevertheless, the deferred path will always be executed.'),
                    'details' => $details,
                ];
            }
        }
        return $modules;
    }

    public function loadAllWorkflowModules()
    {
        $phpModuleFiles = $this->__listPHPModuleFiles();
        foreach ($phpModuleFiles as $type => $files) {
            $classModuleFromFiles = $this->__getClassFromModuleFiles($type, $files);
            foreach ($classModuleFromFiles['classConfigs'] as $i => $config) {
                $classModuleFromFiles['classConfigs'][$i]['module_type'] = $type;
            }
            $this->loaded_modules[$type] = $classModuleFromFiles['classConfigs'];
            $this->loaded_classes[$type] = $classModuleFromFiles['instancedClasses'];
        }
    }

    private function __listPHPModuleFiles()
    {
        $dirs = ['trigger', 'logic', 'action'];
        $files = [];
        foreach ($dirs as $dir) {
            $folder = new Folder(Workflow::MODULE_ROOT_PATH . $dir);
            $filesInFolder = $folder->find('.*\.php', true);
            $files[$dir] = array_diff($filesInFolder, ['..', '.']);
        }
        return $files;
    }

    private function __getClassFromModuleFiles($type, $files)
    {
        $instancedClasses = [];
        $classConfigs = [];
        foreach ($files as $filename) {
            $filepath = sprintf('%s%s/%s', Workflow::MODULE_ROOT_PATH, $type, $filename);
            $instancedClass = $this->__getClassFromModuleFile($filepath);
            if (is_string($instancedClass)) {
                $message = sprintf(__('Error while trying to load module: %s'), $instancedClass);
                $this->__logLoadingError($filename, $message);
            }
            $classConfigs[$instancedClass->id] = $instancedClass->getConfig();
            $instancedClasses[$instancedClass->id] = $instancedClass;
        }
        return [
            'classConfigs' => $classConfigs,
            'instancedClasses' => $instancedClasses,
        ];
    }

    private function __logLoadingError($id, $message)
    {
        $this->Log = ClassRegistry::init('Log');
        $this->Log->createLogEntry('SYSTEM', 'load_module', 'Workflow', $id, $message);
        return false;
    }

    /**
     * getProcessorClass
     *
     * @param  string $filePath
     * @param  string $processorMainClassName
     * @return object|string Object loading success, string containing the error if failure
     */
    private function __getClassFromModuleFile($filepath)
    {
        $className = explode('/', $filepath);
        $className = str_replace('.php', '', $className[count($className)-1]);
        try {
            require_once($filepath);
            try {
                $reflection = new \ReflectionClass($className);
            } catch (\ReflectionException $e) {
                return $e->getMessage();
            }
            $mainClass = $reflection->newInstance(true);
            if ($mainClass->checkLoading() === 'The Factory Must Grow') {
                return $mainClass;
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function getModulesByType($module_type=false): array
    {
        $blocks_trigger = $this->loaded_modules['trigger'];
        $blocks_logic = $this->loaded_modules['logic'];
        $blocks_action = $this->loaded_modules['action'];

        array_walk($blocks_trigger, function(&$block) {
            $block['html_template'] = !empty($block['html_template']) ? $block['html_template'] : 'trigger';
            $block['disabled'] = !empty($block['disabled']);
        });
        $modules = [
            'blocks_trigger' => $blocks_trigger,
            'blocks_logic' => $blocks_logic,
            'blocks_action' => $blocks_action,
        ];
        if (!empty($module_type)) {
            if (!empty($modules[$module_type])) {
                return $modules['block_' . $module_type];
            } else {
                return [];
            }
        }
        return $modules;
    }

    public function getModules($module_type = false): array
    {
        $modulesByType = $this->getModulesByType();
        return array_merge($modulesByType['blocks_trigger'], $modulesByType['blocks_logic'], $modulesByType['blocks_action']);
    }

    /**
     * getModules Return the module from the provided ID
     *
     * @param string|array $module_ids
     * @return array
     */
    public function getModuleByID($module_ids): array
    {
        $returnAString = false;
        if (!is_array($module_ids)) {
            $returnAString = true;
            $module_ids = [$module_ids];
        }
        $matchingModules = [];
        // $modules = $this->getModules()['blocks_all'];
        $modules = $this->getModules();
        foreach ($modules as $module) {
            if (in_array($module['id'], $module_ids)) {
                $matchingModules[] = $module;
            }
        }
        if (empty($matchingModules)) {
            return [];
        }
        return $returnAString ? $matchingModules[0] : $matchingModules;
    }

    /**
     * fetchWorkflows ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  array $options
     * @param  bool  $full
     * @return array
     */
    public function fetchWorkflows(array $user, array $options = array(), $full = false)
    {
        $params = array(
            'conditions' => $this->buildACLConditions($user),
            'contain' => $this->defaultContain,
            'recursive' => -1
        );
        if ($full) {
            $params['recursive'] = 1;
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = !empty($options['group']) ? $options['group'] : false;
        }
        if (isset($options['contain'])) {
            $params['contain'] = !empty($options['contain']) ? $options['contain'] : [];
        }
        if (isset($options['order'])) {
            $params['order'] = !empty($options['order']) ? $options['order'] : [];
        }
        $workflows = $this->find('all', $params);
        return $workflows;
    }

    /**
     * fetchWorkflow ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  int|string $id
     * @param  bool $throwErrors
     * @return array
     */
    public function fetchWorkflow(array $user, $id, bool $throwErrors = true)
    {
        $options = [];
        if (is_numeric($id)) {
            $options = ['conditions' => ["Workflow.id" => $id]];
        } elseif (Validation::uuid($id)) {
            $options = ['conditions' => ["Workflow.uuid" => $id]];
        } else {
            if ($throwErrors) {
                throw new NotFoundException(__('Invalid workflow'));
            }
            return [];
        }
        $workflow = $this->fetchWorkflows($user, $options);
        if (empty($workflow)) {
            throw new NotFoundException(__('Invalid workflow'));
        }
        return $workflow[0];
    }

    /**
     * editWorkflow Edit a worflow
     *
     * @param  array $user
     * @param  array $workflow
     * @return array Any errors preventing the edition
     */
    public function editWorkflow(array $user, array $workflow)
    {
        $errors = array();
        if (!isset($workflow['Workflow']['uuid'])) {
            $errors[] = __('Workflow doesn\'t have an UUID');
            return $errors;
        }
        $existingWorkflow = $this->fetchWorkflow($user, $workflow['Workflow']['id']);
        $workflow['Workflow']['id'] = $existingWorkflow['Workflow']['id'];
        unset($workflow['Workflow']['timestamp']);
        $errors = $this->saveAndReturnErrors($workflow, ['fieldList' => self::CAPTURE_FIELDS], $errors);
        return $errors;
    }

    /**
     * fetchWorkflow ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  int|string $id
     * @param  bool $enable
     * @param  bool $throwErrors
     * @return array
     */
    public function toggleWorkflow(array $user, $id, $enable=true, bool $throwErrors=true)
    {
        $errors = array();
        $workflow = $this->fetchWorkflow($user, $id, $throwErrors);
        $workflow['Workflow']['enabled'] = $enable;
        $errors = $this->saveAndReturnErrors($workflow, ['fieldList' => ['enabled']], $errors);
        return $errors;
    }

    private function saveAndReturnErrors($data, $saveOptions = [], $errors = [])
    {
        $saveSuccess = $this->save($data, $saveOptions);
        if (!$saveSuccess) {
            foreach ($this->validationErrors as $validationError) {
                $errors[] = $validationError[0];
            }
        }
        return $errors;
    }
    
}