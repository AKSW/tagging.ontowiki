<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2012, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * tagging controller
 *
 * @category  ontowiki
 * @package   ontowiki_extensions_tagging
 * @author   {@link http://sebastian.tramp.name Sebastian Tramp}
 */

class TaggingController extends OntoWiki_Controller_Component
{
    public $newmessage = '';
    public $messagetype = '';

    // provided: _owApp, _config, _session, _erfurt
    private $_model;
    private $_store;
    private $_translate;
    private $_ac;
    private $_resource;

    public function init()
    {
        parent::init();
        $this->_store     = $this->_erfurt->getStore();
        $this->_translate = $this->_owApp->translate;
        $this->_resource  = $this->_owApp->selectedResource;
        $this->_session   = $this->_owApp->session;
        $this->_model     = $this->_owApp->selectedModel;
        $this->_ac        = $this->_erfurt->getAc();

        if (isset($this->_request->m)) {
            $this->_model = $store->getModel($this->_request->m);
        }
        if (empty($this->_model)) {
            throw new OntoWiki_Exception(
                'Missing parameter m (model) and no selected model in session!'
            );
        }

        // The tagging controller needs no view renderer
        $this->_helper->viewRenderer->setNoRender();
    }

    /**
     * list all tags by rendering an RDFa enhanced ol list
     */
    public function listtagsAction()
    {
        foreach ($this->_getResources() as $singleResource) {
            // set view results
            $this->view->tags = $this->getTagsForResource($singleResource);
            if (empty($this->view->tags)) {
                $this->newmessage = $this->_translate->_('No tags yet.');
                $this->messagetype = 'info';
            }

            if (!empty($this->newmessage)) {
                $this->view->message = $this->newmessage;
                if (!empty($this->messagetype)) {
                    $this->view->messagetype = $this->messagetype;
                } else {
                    $this->view->messagetype = 'info';
                }
            }

            // Render the Tags
            $listTagsTemplate = 'tagging/listtags.phtml';

            // tagging controller needs no view renderer
            $this->_helper->viewRenderer->setNoRender();
            $this->_response->setBody($this->view->render($listTagsTemplate));
        }
    }

    /**
     * Add new tag for resource
     *
     * params:
     *  - resources: which resource we want to tag
     *  - tagresource:
     *  - tag:
     */
    public function addtagAction()
    {
        // Model Based Access Control
        if (!$this->_ac->isModelAllowed('edit', $this->_model->getModelIri()) ) {
            throw new Erfurt_Ac_Exception('You are not allowed to add tags in this model.');
        }

        $store    = $this->_store;
        $model    = $this->_model;
        $response = $this->getResponse();
        $modelURI = $this->_model->getModelIri();
        $conf     = $this->_privateConfig;
        $request  = $this->_request;


        // fetch tagresource and/or tag parameter
        $tagLabel    = null;
        $tagResource = null;
        if (isset($request->tag)) {
            $tagLabel = trim($request->getParam('tag'));
        }
        if (isset($request->tagresource)) {
            $tagResource = $request->getParam('tagresource');
        }

        // check, if newtag true or false
        if (isset($request->newtag)) {
            $newtag = (bool) $request->getParam('newtag');
        } else {
            $newtag = (bool) $conf->defaults->newtag;
        }

        // if both tagResource and tag are missing
        if ($tagResource == null && $tagLabel == null) {
            $this->messagetype = 'error';
            $this->newmessage = 'Please provide a valid tagname';
        }


        foreach ($this->_getResources() as $taggedResource) {
            $this->_addTagging(
                $taggedResource,
                $tagResource,
                $tagLabel,
                $newtag
            );
        }

        // Render the tags
        $tags = $this->listtagsAction();
    }


    /**
     * returns an array of tags associated with the resource
     * returns an empty array if no tag available
     *
     * @param string $resource the resource URI
     */
    private function getTagsForResource ($resource)
    {
        $tags = array();

        // get all tagresources and properties for this resource
        // note: property is used for RDFa and maybe
        // later more than prop is fetched here
        $tagsQuery = "SELECT DISTINCT * WHERE {
            <".$resource."> <".$this->_privateConfig->tagproperty."> ?uri .
            <".$resource."> ?property ?uri .
        }";
        $tagsresult = $this->_model->sparqlQuery($tagsQuery);
        if (!empty($tagsresult)) {

            // ok, we have tags, so start and feed the titleHelper
            $titleHelper = new OntoWiki_Model_TitleHelper($this->_model);
            foreach ($tagsresult as $tag) {
                $titleHelper->addResource($tag['uri']);
            }

            // for link creation
            $linkurl = new OntoWiki_Url(array('route' => 'properties'), array('r'));

            // now fetch the titles and feed the view result
            $tagcount = 0;
            $unsortedTags = array();
            $unsortedKeys = array();
            foreach ($tagsresult as $tag) {
                /*
                 * prepare tagdata (uri and property is
                 * already set from the query
                 */
                $tag['title'] = $titleHelper->getTitle($tag['uri']);
                $tag['link'] = (string) $linkurl->setParam('r', $tag['uri'], true);

                // add tag to result tags and feed title sort array
                $unsortedTags[ $tag['title'].$tagcount ] = $tag;
                $unsortedKeys[] = $tag['title'].$tagcount;
                $tagcount++;
            }

            // sort the tagsoutput according to the key (title+counter)
            // maybe here we can multisort?
            natcasesort($unsortedKeys);
            foreach ($unsortedKeys as $key) {
                $tags[] = $unsortedTags[$key];
            }
            #var_dump($tags);
        }
        return $tags;
    }
    /*
     * returns an array of resource URIs which will be tagged
     */
    private function _getResources()
    {
        // fetch resources parameter
        if (isset($this->_request->resources)) {
            $resourcesJson = $this->_request->getParam('resources');
        } else {
            throw new OntoWiki_Exception('Missing parameter resources!');
        }

        // extract and validate json
        $resources = json_decode($resourcesJson);
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new OntoWiki_Exception('Invalid parameter resources! Json Error: ' . json_last_error());
        }

        return $resources;
    }

    /**
     * Generate TagResource for tag, which will be added
     *
     * @return string $tagResource the new generated tagresource URI
     */
    private function _getUniqueUri() {
        $modelURI = $this->_model->getModelIri();
        $username = $this->_owApp->getUser()->getUsername();
        // generate new unique tagresource using model, prefix "tags" and hash from (user+date)
        return $modelURI."tags/".md5($username ." ". date("F j, Y, g:i:s:u a"));
    }

    /**
     * Add tag by given resource tagResource and/or tag
     * @param string $resources the resources URI
     * @param string $tag the tag label
     * @return unknown_type
     */
    private function _addTagging($taggedUri, $tagUri = null, $tagLabel = null, $alwaysNew = false)
    {
        $versioning = $this->_erfurt->getVersioning();
        $model      = $this->_model;
        $createTag  = false;

        // create a new URI if we want a new tag resource
        if ($alwaysNew) {
            $tagUri    = $this->_getUniqueUri();
            $createTag = true;
        }
        // if we still do not have a tag resource, search for, than create one
        if ($tagUri == null) {
            // search for tag resource by tagLabel
            $tagUri = $this->_getResourceByLabel($tagLabel);
            // if not found -> create new
            if ($tagUri == null) {
                $tagUri    = $this->_getUniqueUri();
                $createTag = true;
            }
        }

        // fetch / create MemoryModels for investigation
        $taggedMM = $model->getResource($taggedUri)->getMemoryModel();
        if ($createTag) {
            $tagMM = new Erfurt_Rdf_MemoryModel();
        } else {
            $tagMM = $model->getResource($tagUri)->getMemoryModel();
        }

        // create resource -> tag relation
        $actionSpec                = array();
        $actionSpec['modeluri']    = (string) $model;
        $actionSpec['resourceuri'] = $taggedUri;
        $actionSpec['type'] = 132; // resource getaggt
        $versioning->startAction($actionSpec);
        $model->addStatement(
            $taggedUri,
            $this->_privateConfig->tagproperty,
            array('value' => $tagUri, 'type' => 'uri')
        );
        $versioning->endAction($actionSpec);

        if ($createTag) {
            // create tag (type and label / name literal)
            $actionSpec                = array();
            $actionSpec['modeluri']    = (string) $model;
            $actionSpec['resourceuri'] = $tagUri;
            $actionSpec['type'] = 131; // tag created
            $versioning->startAction($actionSpec);
            $model->addStatement(
                $tagUri,
                $this->_privateConfig->resOf,
                array('value' => $this->_privateConfig->tagclass, 'type' => 'uri')
            );
            if ($tagLabel != null) {
                $model->addStatement(
                    $tagUri,
                    $this->_privateConfig->tagname,
                    array('value' => $tagLabel, 'type' => 'literal')
                );
            }
            $versioning->endAction($actionSpec);
        }

        $this->messagetype = 'success';
        $this->newmessage  = 'Resource tagged';
    }

    /*
     * returns an URI string if a resource was found or null if not
     */
    private function _getResourceByLabel($label)
    {
        if ($label == null) {
            return null;
        }

        // get tagresource for the selected tag
        $query = 'SELECT ?resource WHERE {' . PHP_EOL .
            '?resource <'.$this->_privateConfig->tagname.'> ?literal .' . PHP_EOL .
            'FILTER (regex(?literal, "^' . $label . '$", "i"))' . PHP_EOL .
            '} LIMIT 1';
        $exist = $this->_model->sparqlQuery($query);

        if (!empty($exist)) {
            return (string) $exist[0]['resource'];
        } else {
            return null;
        }
    }

}
