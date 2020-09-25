<?php
App::uses('AppModel', 'Model');

class GalaxyClusterRelation extends AppModel
{
    public $useTable = 'galaxy_cluster_relations';

    public $recursive = -1;

    public $actsAs = array(
            'Containable',
    );

    public $validate = array(
        'referenced_galaxy_cluster_type' => array(
            'stringNotEmpty' => array(
                'rule' => array('stringNotEmpty')
            )
        ),
        'galaxy_cluster_uuid' => array(
            'uuid' => array(
                'rule' => array('custom', '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/'),
                'message' => 'Please provide a valid UUID'
            ),
        ),
        'referenced_galaxy_cluster_uuid' => array(
            'uuid' => array(
                'rule' => array('custom', '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/'),
                'message' => 'Please provide a valid UUID'
            ),
        ),
        'distribution' => array(
            'rule' => array('inList', array('0', '1', '2', '3', '4')),
            'message' => 'Options: Your organisation only, This community only, Connected communities, All communities, Sharing group',
            'required' => true
        )
    );

    public $belongsTo = array(
            'SourceCluster' => array(
                'className' => 'GalaxyCluster',
                'foreignKey' => 'galaxy_cluster_id',
            ),
            'TargetCluster' => array(
                'className' => 'GalaxyCluster',
                'foreignKey' => 'referenced_galaxy_cluster_id',
            ),
            'SharingGroup' => array(
                    'className' => 'SharingGroup',
                    'foreignKey' => 'sharing_group_id'
            ),
    );

    public $hasMany = array(
        'GalaxyClusterRelationTag' => array('dependent' => true),
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (isset($results[$k]['TargetCluster']) && key_exists('id', $results[$k]['TargetCluster']) && is_null($results[$k]['TargetCluster']['id'])) {
                $results[$k]['TargetCluster'] = array();
            }
            if (isset($results[$k]['GalaxyClusterRelation']['distribution']) && $results[$k]['GalaxyClusterRelation']['distribution'] != 4) {
                unset($results[$k]['SharingGroup']);
            }
        }
        return $results;
    }

    public function buildConditions($user, $clusterConditions = true)
    {
        $this->Event = ClassRegistry::init('Event');
        $conditions = [];
        if (!$user['Role']['perm_site_admin']) {
            $alias = $this->alias;
            $sgids = $this->Event->cacheSgids($user, true);
            $conditionsRelations['AND']['OR'] = [
                [
                    'AND' => [
                        "${alias}.distribution >" => 0,
                        "${alias}.distribution <" => 4
                    ],
                ],
                [
                    'AND' => [
                        "${alias}.sharing_group_id" => $sgids,
                        "${alias}.distribution" => 4
                    ]
                ]
            ];
            $conditionsSourceCluster = $clusterConditions ? $this->SourceCluster->buildConditions($user) : [];
            $conditions = [
                'AND' => [
                    $conditionsRelations,
                    $conditionsSourceCluster
                ]
            ];
        }
        return $conditions;
    }

    public function fetchRelations($user, $options, $full=false)
    {
        $params = array(
            'conditions' => $this->buildConditions($user),
            'recursive' => -1
        );
        if (!empty($options['contain'])) {
            $params['contain'] = $options['contain'];
        } elseif ($full) {
            $params['contain'] = array('SharingGroup', 'SourceCluster', 'TargetCluster');
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = empty($options['group']) ? $options['group'] : false;
        }
        $relations = $this->find('all', $params);
        return $relations;
    }

    public function getExistingRelationships()
    {
        $existingRelationships = $this->find('list', array(
            'recursive' => -1,
            'fields' => array('referenced_galaxy_cluster_type', 'referenced_galaxy_cluster_type'),
            'group' => array('referenced_galaxy_cluster_type')
        ), false, false);
        return $existingRelationships;
    }

    public function deleteRelations($conditions)
    {
        $this->deleteAll($conditions, false, false);
    }

    public function massageRelationTag($cluster)
    {
        if (!empty($cluster['GalaxyCluster'][$this->alias])) {
            foreach ($cluster['GalaxyCluster'][$this->alias] as $k => $relation) {
                if (!empty($relation['GalaxyClusterRelationTag'])) {
                    foreach ($relation['GalaxyClusterRelationTag'] as $relationTag) {
                        $cluster['GalaxyCluster'][$this->alias][$k]['Tag'][] = $relationTag['Tag'];
                    }
                }
                unset($cluster['GalaxyCluster'][$this->alias][$k]['GalaxyClusterRelationTag']);
            }
        }
        return $cluster;
    }
    
    /**
     * saveRelations
     *
     * @see saveRelation
     * @return array List of errors if any
     */
    public function saveRelations(array $user, array $cluster, array $relations, $captureTag=false, $force=false)
    {
        $errors = array();
        foreach ($relations as $k => $relation) {
            $saveResult = $this->saveRelation($user, $cluster, $relation, $captureTag=$captureTag, $force=$force);
            $errors = array_merge($errors, $saveResult);
        }
        return $errors;
    }
    
    /**
     * saveRelation Respecting ACL saves a relation and set correct fields where applicable.
     * Contrary to its capture equivalent, trying to save a relation for a unknown target cluster will fail.
     *
     * @param  array $user
     * @param  array $cluster       The cluster from which the relation is originating
     * @param  array $relation      The relation to save
     * @param  bool  $captureTag    Should the tag be captured if it doesn't exists
     * @param  bool  $force         Should the relation be edited if it exists
     * @return array List errors if any
     */
    public function saveRelation(array $user, array $cluster, array $relation, $captureTag=false, $force=false)
    {
        $errors = array();
        if (!isset($relation['GalaxyClusterRelation']) && !empty($relation)) {
            $relation = array('GalaxyClusterRelation' => $relation);
        }
        $authorizationCheck = $this->SourceCluster->fetchIfAuthorized($user, $cluster, array('edit'), $throwErrors=false, $full=false);
        if (isset($authorizationCheck['authorized']) && !$authorizationCheck['authorized']) {
            $errors[] = $authorizationCheck['error'];
            return $errors;
        }
        $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $cluster['uuid'];

        $existingRelation = $this->find('first', array('conditions' => array(
            'GalaxyClusterRelation.galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'],
            'GalaxyClusterRelation.referenced_galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
            'GalaxyClusterRelation.referenced_galaxy_cluster_type' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_type'],
        )));
        if (!empty($existingRelation)) {
            if (!$force) {
                $errors[] = __('Relation already exists');
                return $errors;
            } else {
                $relation['GalaxyClusterRelation']['id'] = $existingRelation['GalaxyClusterRelation']['id'];
            }
        } else {
            $this->create();
        }
        if (empty($errors)) {
            if (!isset($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                $errors[] = __('referenced_galaxy_cluster_uuid not provided');
                return $errors;
            }
            if (!$force) {
                $targetCluster = $this->TargetCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], 'view', $throwErrors=false, $full=false);
                if (isset($targetCluster['authorized']) && !$targetCluster['authorized']) { // do not save the relation if referenced cluster is not accessible by the user (or does not exists)
                    $errors[] = array(__('Invalid referenced galaxy cluster'));
                    return $errors;
                }
            }
            $relation = $this->syncUUIDsAndIDs($user, $relation);
            $saveSuccess = $this->save($relation);
            if ($saveSuccess) {
                $savedRelation = $this->find('first', array(
                    'conditions' => array('id' =>  $this->id),
                    'recursive' => -1
                ));
                $tags = array();
                if (!empty($relation['GalaxyClusterRelation']['tags'])) {
                    $tags = $relation['GalaxyClusterRelation']['tags'];
                } elseif (!empty($relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'])) {
                    $tags = $relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'];
                    $tags = Hash::extract($tags, '{n}.name');
                } elseif (!empty($relation['GalaxyClusterRelation']['Tag'])) {
                    $tags = $relation['GalaxyClusterRelation']['Tag'];
                    $tags = Hash::extract($tags, '{n}.name');
                }

                if (!empty($tags)) {
                    $tagSaveResults = $this->GalaxyClusterRelationTag->attachTags($user, $this->id, $tags, $capture=$captureTag);
                    if (!$tagSaveResults) {
                        $errors[] = __('Tags could not be saved for relation (%s)', $this->id);
                    }
                }
            } else {
                foreach ($this->validationErrors as $validationError) {
                    $errors[] = $validationError[0];
                }
            }
        }
        return $errors;
    }

    /**
     * editRelation Respecting ACL edits a relation and set correct fields where applicable.
     * Contrary to its capture equivalent, trying to save a relation for a unknown target cluster will fail.
     *
     * @param  array $user
     * @param  array $relation      The relation to be saved
     * @param  array $fieldList     Only edit the fields provided
     * @param  bool  $captureTag    Should the tag be captured if it doesn't exists
     * @return array List of errors if any
     */
    public function editRelation(array $user, array $relation, array $fieldList=array(), $captureTag=false)
    {
        $this->SharingGroup = ClassRegistry::init('SharingGroup');
        $errors = array();
        if (!isset($relation['GalaxyClusterRelation']['galaxy_cluster_id'])) {
            $errors[] = __('galaxy_cluster_id not provided');
            return $errors;
        }
        $authorizationCheck = $this->SourceCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['galaxy_cluster_id'], array('edit'), $throwErrors=false, $full=false);
        if (isset($authorizationCheck['authorized']) && !$authorizationCheck['authorized']) {
            $errors[] = $authorizationCheck['error'];
            return $errors;
        }

        if (isset($relation['GalaxyClusterRelation']['id'])) {
            $existingRelation = $this->find('first', array('conditions' => array('GalaxyClusterRelation.id' => $relation['GalaxyClusterRelation']['id'])));
        } else {
            $errors[] = __('UUID not provided');
        }
        if (empty($existingRelation)) {
            $errors[] = __('Unkown ID');
        } else {
            $options = array('conditions' => array(
                'uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid']
            ));
            $cluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
            if (empty($cluster)) {
                $errors[] = __('Invalid source galaxy cluster');
            }
            $cluster = $cluster[0];
            $relation['GalaxyClusterRelation']['id'] = $existingRelation['GalaxyClusterRelation']['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $cluster['SourceCluster']['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $cluster['SourceCluster']['uuid'];

            if (isset($relation['GalaxyClusterRelation']['distribution']) && $relation['GalaxyClusterRelation']['distribution'] == 4 && !$this->SharingGroup->checkIfAuthorised($user, $relation['GalaxyClusterRelation']['sharing_group_id'])) {
                $errors[] = array(__('Galaxy Cluster Relation could not be saved: The user has to have access to the sharing group in order to be able to edit it.'));
            }

            if (empty($errors)) {
                if (!isset($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                    $errors[] = __('referenced_galaxy_cluster_uuid not provided');
                    return $errors;
                }
                $targetCluster = $this->TargetCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], 'view', $throwErrors=false, $full=false);
                if (isset($targetCluster['authorized']) && !$targetCluster['authorized']) { // do not save the relation if referenced cluster is not accessible by the user (or does not exists)
                    $errors[] = array(__('Invalid referenced galaxy cluster'));
                    return $errors;
                }
                $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $targetCluster['TargetCluster']['id'];
                $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'] = $targetCluster['TargetCluster']['uuid'];
                $relation['GalaxyClusterRelation']['default'] = false;
                if (empty($fieldList)) {
                    $fieldList = array('galaxy_cluster_id', 'galaxy_cluster_uuid', 'referenced_galaxy_cluster_id', 'referenced_galaxy_cluster_uuid', 'referenced_galaxy_cluster_type', 'distribution', 'sharing_group_id', 'default');
                }

                $saveSuccess = $this->save($relation, array('fieldList' => $fieldList));
                if (!$saveSuccess) {
                    foreach ($this->validationErrors as $validationError) {
                        $errors[] = $validationError[0];
                    }
                } else {
                    $this->GalaxyClusterRelationTag->deleteAll(array('GalaxyClusterRelationTag.galaxy_cluster_relation_id' => $relation['GalaxyClusterRelation']['id']));
                    $this->GalaxyClusterRelationTag->attachTags($user, $relation['GalaxyClusterRelation']['id'], $relation['GalaxyClusterRelation']['tags'], $capture=$captureTag);
                }
            }
        }
        return $errors;
    }

    /**
     * Gets a relation then save it.
     *
     * @param array $user
     * @param array $cluster    The cluster for which the relation is being saved
     * @param array $relation   The relation to be saved
     * @param bool  $fromPull   If the current capture is performed from a PULL sync. If set, it allows edition of existing relations
     * @return array The capture success results
     */
    public function captureRelations(array $user, array $cluster, array $relations, $fromPull=false)
    {
        $results = array('success' => false, 'imported' => 0, 'failed' => 0);
        $this->Log = ClassRegistry::init('Log');
        $clusterUuid = $cluster['GalaxyCluster']['uuid'];

        foreach ($relations as $k => $relation) {
            if (!isset($relation['GalaxyClusterRelation'])) {
                $relation = array('GalaxyClusterRelation' => $relation);
            }
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $clusterUuid;
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $cluster['GalaxyCluster']['id'];
            
            if (empty($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                $this->Log->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('No referenced cluster UUID provided'), __('relation for cluster (%s)', $clusterUuid));
                $results['failed']++;
                continue;
            } else {
                $options = array(
                    'conditions' => array(
                        'uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
                    ),
                    'fields' => array(
                        'id', 'uuid',
                    )
                );
                $referencedCluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
                if (empty($referencedCluster)) {
                    if (!$fromPull) {
                        $this->Log->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('Referenced cluster not found'), __('relation to (%s) for cluster (%s)', $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], $clusterUuid));
                    }
                    $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = 0;
                } else {
                    $referencedCluster = $referencedCluster[0];
                    $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $referencedCluster['SourceCluster']['id'];
                }
            }

            $existingRelation = $this->find('first', array('conditions' => array(
                'GalaxyClusterRelation.galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'],
                'GalaxyClusterRelation.referenced_galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
                'GalaxyClusterRelation.referenced_galaxy_cluster_type' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_type'],
            )));
            if (!empty($existingRelation)) {
                if (!$fromPull) {
                    $this->Log->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('Relation already exists'), __('relation to (%s) for cluster (%s)', $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], $clusterUuid));
                    $results['failed']++;
                    continue;
                } else {
                    $relation['GalaxyClusterRelation']['id'] = $existingRelation['GalaxyClusterRelation']['id'];
                }
            } else {
                unset($relation['GalaxyClusterRelation']['id']);
                $this->create();
            }

            $this->Event = ClassRegistry::init('Event');
            if (isset($relation['GalaxyClusterRelation']['distribution']) && $relation['GalaxyClusterRelation']['distribution'] == 4) {
                $relation['GalaxyClusterRelation'] = $this->Event->__captureSGForElement($relation['GalaxyClusterRelation'], $user);
            }

            $saveSuccess = $this->save($relation);
            if ($saveSuccess) {
                $results['imported']++;
                $modelKey = false;
                if (!empty($relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'])) {
                    $modelKey = 'GalaxyClusterRelationTag';
                } elseif (!empty($relation['GalaxyClusterRelation']['Tag'])) {
                    $modelKey = 'Tag';
                }
                if ($modelKey !== false) {
                    $tagNames = Hash::extract($relation['GalaxyClusterRelation'][$modelKey], '{n}.name');
                    // Similar behavior as for AttributeTags: Here we only attach tags. If they were removed at some point it's not taken into account.
                    // Since we don't have tag soft-deletion, tags added by users will be kept.
                    $this->GalaxyClusterRelationTag->attachTags($user, $this->id, $tagNames, $capture=true);
                }
            } else {
                $results['failed']++;
            }
        }

        $results['success'] = $results['imported'] > 0;
        return $results;
    }

    public function removeNonAccessibleTargetCluster($user, $relations)
    {
        $availableTargetClusterIDs = $this->TargetCluster->cacheGalaxyClusterIDs($user);
        $availableTargetClusterIDsKeyed = array_flip($availableTargetClusterIDs);
        foreach ($relations as $i => $relation) {
            if (
                isset($relation['TargetCluster']['id']) &&
                !isset($availableTargetClusterIDsKeyed[$relation['TargetCluster']['id']])
            ) {
                $relations[$i]['TargetCluster'] = null;
            }
        }
        return $relations;
    }
    
    /**
     * syncUUIDsAndIDs Adapt IDs of source and target cluster inside the relation based on the provided two UUIDs
     *
     * @param  array $user
     * @param  array $relation
     * @return array The adpated relation
     */
    private function syncUUIDsAndIDs(array $user, array $relation)
    {
        $options = array('conditions' => array(
            'uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid']
        ));
        $sourceCluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
        if (!empty($sourceCluster)) {
            $sourceCluster = $sourceCluster[0];
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $sourceCluster['SourceCluster']['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $sourceCluster['SourceCluster']['uuid'];
        }
        $options = array('conditions' => array(
            'uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid']
        ));
        $targetCluster = $this->TargetCluster->fetchGalaxyClusters($user, $options);
        if (!empty($targetCluster)) {
            $targetCluster = $targetCluster[0];
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $targetCluster['TargetCluster']['id'];
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'] = $targetCluster['TargetCluster']['uuid'];
        } else {
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = 0;
        }
        return $relation;
    }
}
