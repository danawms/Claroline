<?php

namespace Claroline\CoreBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Claroline\CoreBundle\Entity\Resource\ResourceType;
use Claroline\CoreBundle\Entity\Resource\AbstractResource;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\Resource\MimeType;
use Claroline\CoreBundle\Entity\Resource\ResourceInstance;
use Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace;
use Claroline\CoreBundle\Entity\WorkspaceRole;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Form\DirectoryType;
use Claroline\CoreBundle\Form\SelectResourceType;
use Claroline\CoreBundle\Form\ResourceOptionsType;
use Claroline\CoreBundle\Library\Security\SymfonySecurity;

/**
 * This controller will manage resources.
 * It'll delegate create/update/delete/click actions for the different resource types
 * to the specified resource "manager" service.
 * It can add/remove a resource to a workspace.
 *
 * TODO:
 * javascript, add by copy => the response should be the new resource id
 * remove php navigation and replace it by javascript
 * REFACTOR RESOURCE MANANGER
 * - copy/paste
 * - sharable stuff
 * - popups instead of the stuff above
 * - protect getNode functions
 * - tinyMCE for text
 * REFACTOR RESOURCE PICKER
 * ~ creation is done
 * when the server is slow, many ajax request can be sent... and everything get messy.
 * what about the rights in general ?
 * instance suppression: do we supress the original aswell ? can the creator remove every instance ?
 * Redirections & Responses
 * improved tests for move/add to workspace
 * text diff
 * linker
 * edit by copy
 * not using jquery from twitter bootstrap ? currently there is not enough room for 2 data trees in the js index and it should be fixed
 * create a resource manager in ordrer to remove some logic from the ResourceController.
 */
class ResourceController extends Controller
{

    /**
     * Renders the root resources for the personnal workspace of the current
     * logged user.
     *
     * @return Response
     */
    public function indexAction()
    {
        $user = $this->get('security.context')->getToken()->getUser();
        $em = $this->getDoctrine()->getEntityManager();
        $formResource = $this->get('form.factory')->create(new SelectResourceType(), new ResourceType());
        $personnalWs = $user->getPersonnalWorkspace();
        $resourceInstances = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->getWSListableRootResource($personnalWs);
        $resourcesType = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')->findAll();

        return $this->render(
                'ClarolineCoreBundle:Resource:index.html.twig', array('form_resource' => $formResource->createView(), 'resourceInstances' => $resourceInstances, 'parentId' => null, 'resourcesType' => $resourcesType, 'workspace' => $personnalWs)
        );
    }

    /**
     * Renders the resource form with its claroline layout
     *
     * @param integer $instanceParentId the parent resourceInstance id. It can be 'null' if there is no parent.
     *
     * @return Response
     */
    public function creationResourceFormAction($instanceParentId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $parent = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceParentId);

        if ($this->get('security.context')->isGranted('EDIT', $parent) || $parent === null) {
            $request = $this->get('request');
            $form = $request->request->get('select_resource_form');
            $idType = $form['type'];
            $resourceType = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')->find($idType);
            $rsrcServName = $this->findResService($resourceType);
            $rsrcServ = $this->get($rsrcServName);
            $twigFile = 'ClarolineCoreBundle:Resource:form_page.html.twig';
            $content = $rsrcServ->getFormPage($twigFile, $instanceParentId, $resourceType->getType());

            return new Response($content);
        }

        throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
    }

    /**
     * Returns the resource options form.
     * name & share_type can be edited for every resource as long the user has the rights to do
     *
     * @param integer $instanceId
     */
    public function creationOptionsFormAction($instanceId)
    {
        $res = $this->getDoctrine()
            ->getEntityManager()
            ->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')
            ->find($instanceId)
            ->getResource();

        $form = $this->get('form.factory')->create(new ResourceOptionsType(), $res);

        $request = $this->get('request');

        if ($request->isXmlHttpRequest()) {
            return $this->render('ClarolineCoreBundle:Resource:options_form.html.twig', array('instanceId' => $instanceId, 'form' => $form->createView()));
        }

        return $this->render('ClarolineCoreBundle:Resource:options_form_page.html.twig', array('instanceId' => $instanceId, 'form' => $form->createView()));
    }

    /**
     * Edits the resource options
     *
     * @param integer $resourceId
     */
    public function editOptionsAction($instanceId)
    {
        $request = $this->get('request');
        $em = $this->getDoctrine()->getEntityManager();
        $res = $em->getRepository('ClarolineCoreBundle:Resource\ResourceInstance')->find($instanceId)->getResource();
        $form = $this->createForm(new ResourceOptionsType(), $res);
        $form->bindRequest($request);

        if ($form->isValid()) {
            $res = $form->getData();
            $em->persist($res);
            $em->flush();

            if ($request->isXmlHttpRequest()) {
                $ri = $em->getRepository('ClarolineCoreBundle:Resource\ResourceInstance')->find($instanceId);
                $ri->setResource($res);
                $content = $this->renderView("ClarolineCoreBundle:Resource:dynatree_resource.json.twig", array('resources' => array($ri)));
                $response = new Response($content);
                $response->headers->set('Content-Type', 'application/json');

                return $response;
            }

            return "edited";
        } else {
            if ($request->isXmlHttpRequest()) {
                return $this->render('ClarolineCoreBundle:Resource:options_form.html.twig', array('instanceId' => $instanceId, 'form' => $form->createView()));
            }
            return $this->render('ClarolineCoreBundle:Resource:options_form_page.html.twig', array('instanceId' => $instanceId, 'form' => $form->createView()));
        }
    }

    /**
     * Renders the specific resource form with it's claroline layout
     *
     * @param integer $instanceParentId
     * @param string  $type
     *
     * @return Response
     */
    public function getFormAction($instanceParentId, $type)
    {
        $resourceType = $this->getDoctrine()->getEntityManager()->getRepository("Claroline\CoreBundle\Entity\Resource\ResourceType")->findOneBy(array('type' => $type));
        $name = $this->findResService($resourceType);
        $rsrcServ = $this->get($name);
        $twigFile = 'ClarolineCoreBundle:Resource:generic_form.html.twig';
        $content = $rsrcServ->getFormPage($twigFile, $instanceParentId, $type);

        return new Response($content);
    }

    /**
     * Adds a resource. This method will delegate the resource creation to
     * the correct ResourceType service.
     *
     * if the workspaceId is 'null', the workspace will be the current user personnal Ws.
     * if it was requested through ajax, it'll respond with a json object containing the created resource datas
     * otherwise it'll redirect to the resource index.
     *
     * @param string  $type
     * @param integer $instanceParentId
     * @param integer $workspaceId
     *
     * @return Response|RedirectResponse
     */
    public function createAction($type, $instanceParentId, $workspaceId)
    {
        $request = $this->get('request');
        $user = $this->get('security.context')->getToken()->getUser();

        if (null === $workspaceId) {
            $workspaceId = $user->getPersonnalWorkspace()->getId();
        }

        $resourceType = $this->getDoctrine()->getEntityManager()->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')->findOneBy(array('type' => $type));
        $name = $this->findResService($resourceType);
        $form = $this->get($name)->getForm();
        $form->bindRequest($request);

        if ($form->isValid()) {
            $data = $form->getData();
            $ri = $this->get('claroline.resource.creator')->create($data, $workspaceId, $instanceParentId, $data, true);

            if (null !== $ri) {
                $content = $this->renderView("ClarolineCoreBundle:Resource:dynatree_resource.json.twig", array('resources' => array($ri)));
                $response = new Response($content);
                $response->headers->set('Content-Type', 'application/json');

                return $response;
            }
        }

        $content = $this->renderView(
            'ClarolineCoreBundle:Resource:generic_form.html.twig', array('form' => $form->createView(), 'parentId' => $instanceParentId, 'type' => $type)
        );
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * This method will redirect to the ResourceInstance ResourceType manager
     * defaultClickAction.
     * /!\ 'directory' type service works with resource instances instead of resources
     *
     * @param integer $instanceId
     *
     * @return Response
     *
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     */
    public function defaultClickAction($instanceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);

        if (!$this->get('security.context')->isGranted('VIEW', $resourceInstance)) {
            throw new AccessDeniedException();
        } else {
            $resourceType = $resourceInstance->getResource()->getResourceType();
            $name = $this->findResService($resourceType);

            if ('directory' !== $resourceType->getType()) {
                $response = $this->get($name)->getDefaultAction($resourceInstance->getResource()->getId());
            } else {
                $response = $this->get($name)->getDefaultAction($instanceId);
            }
        }

        return $response;
    }

    /**
     * This method will redirect to the ResourceInstance ResourceType manager
     * indexAction.
     *
     * @param integer $instanceId
     * @param integer $workspaceId
     *
     * @return Response
     *
     * @throws AccessDeniedException
     */
    public function openAction($instanceId)
    {
        $resourceInstance = $this->getDoctrine()
            ->getEntityManager()
            ->getRepository('ClarolineCoreBundle:Resource\ResourceInstance')
            ->find($instanceId);

        if (!$this->get('security.context')->isGranted('VIEW', $resourceInstance)) {
            throw new AccessDeniedException();
        } else {
            $resourceType = $resourceInstance->getResourceType();
            $name = $this->findResService($resourceType);
            $response = $this->get($name)->indexAction($resourceInstance->getResource()->getId());

            return new Response($response);
        }
    }

    /**
     * This method will redirect to the ResourceInstance ResourceType manager
     * editAction. Options must be 'ref' or 'copy'
     *
     * @param integer $instanceId
     * @param integer $workspaceId
     * @param string  $options
     *
     * @return Response
     */
    public function editAction($instanceId, $workspaceId, $options)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);

        if ($options == 'copy') {
            $workspace = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->find($workspaceId);
            $user = $this->get('security.context')->getToken()->getUser();
            $name = $this->findResService($resourceInstance->getResourceType());
            $copy = $this->get($name)->copy($resourceInstance->getResource(), $user);

            $instanceCopy = new ResourceInstance();
            $instanceCopy->setParent($resourceInstance->getParent());
            $instanceCopy->setResource($copy);
            $instanceCopy->setCopy(false);
            $instanceCopy->setWorkspace($resourceInstance->getWorkspace());
            $instanceCopy->setCreator($user);

            $copy->setResourceType($resourceInstance->getResourceType());
            $copy->addResourceInstance($instanceCopy);

            $em->persist($copy);
            $em->persist($instanceCopy);
            $em->remove($resourceInstance);
            $em->flush();

            $roleCollaborator = $workspace->getCollaboratorRole();
            $rightManager = $this->get('claroline.security.right_manager');
            $rightManager->addRight($instanceCopy, $roleCollaborator, MaskBuilder::MASK_VIEW);

            return new Response('copied');
        } else {
            $name = $this->findResService($resourceInstance->getResourceType());
            $response = $this->get($name)->editFormPageAction($resourceInstance->getResource()->getId());

            return new Response($response);
        }
    }

    /**
     * Returns a ResourceInstance node for dynatree.
     *
     * @param integer $instanceId
     * @param integer $workspaceId
     * @param string  $format
     *
     * @return Response
     */
    public function getNodeAction($instanceId, $workspaceId, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->find($workspaceId);

        if ($instanceId == 0) {
            $resourcesInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->getWSListableRootResource($workspace);
        } else {
            $parent = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);
            $resourcesInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->getListableChildren($parent);
        }

        $content = $this->renderView("ClarolineCoreBundle:Resource:dynatree_resource.{$format}.twig", array('resources' => $resourcesInstance));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Gets every workspace root nodes for a user
     *
     * @param integer $userId
     * @param string  $format
     *
     * @return Response
     */
    public function getRootNodesAction($userId, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();

        if ('null' != $userId) {
            $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
        } else {
            $user = $this->get('security.context')->getToken()->getUser();
        }

        $workspaces = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->getAllWsOfUser($user);
        $roots = array();

        foreach ($workspaces as $workspace) {
            $root = new ResourceInstance();
            $rootDir = new Directory();
            $rootDir->setId(0);
            $rootDir->setName($workspace->getName());
            $rootDir->setShareType(0);
            $root->setResource($rootDir);
            $root->setId(0);
            $root->setCopy(0);
            $root->setWorkspace($workspace);
            $directoryType = $em->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findOneBy(array('type' => 'directory'));
            $rootDir->setResourceType($directoryType);
            $roots[] = $root;
        }

        $content = $this->renderView("ClarolineCoreBundle:Resource:dynatree_resource.{$format}.twig", array('resources' => $roots));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Moves an resource instance (changes his parent)
     * When it's moved to the root with javascript, the workspaceId isn't know so it must be passed as a parameter
     * otherwise child and parent workspace are equals.
     *
     * @param integer $idChild
     * @param integer $idParent
     * @param integer $workspaceDestinationId
     *
     * @return Response
     */
    public function moveResourceAction($idChild, $idParent, $workspaceDestinationId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $parent = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($idParent);
        $child = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($idChild);


        $workspace = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->find($workspaceDestinationId);

        $this->get('claroline.resource.creator')->move($child, $workspace, $parent);

        return new Response('success');
    }

    /**
     * Adds a resource instance to a workspace.
     * Options must be must be 'ref' or 'copy'.
     * If $instanceId = 0, every resource is added
     * If $instanceDestinationId = 0, everything is added to the root
     *
     * @param integer $resourceId
     * @param integer $workspaceId
     * @param string  $options
     * @param integer $instanceDestinationId
     *
     * @return Response
     */
    public function addToWorkspaceAction($instanceId, $workspaceId, $options, $instanceDestinationId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository('ClarolineCoreBundle:Workspace\AbstractWorkspace')->find($workspaceId);
        $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId)->getResource();
        $parent = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceDestinationId);

        if ($options == 'ref') {
            if ($instanceId == 0) {
                $userWorkspace = $this->get('security.context')->getToken()->getUser()->getPersonnalWorkspace();
                $resourcesInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->getWSListableRootResource($userWorkspace);

                foreach ($resourcesInstance as $resourceInstance) {
                    if ($resource->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                        $this->copyFirstReferenceInstance($workspace, $resourceInstance->getId(), $parent);
                    }
                }
            } else {
                if ($resource->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                    $this->copyFirstReferenceInstance($workspace, $instanceId, $parent);
                }
            }

            $em->flush();
        } else {
            if ($instanceId == 0) {
                $userWorkspace = $this->get('security.context')->getToken()->getUser()->getPersonnalWorkspace();
                $resourcesInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->getWSListableRootResource($userWorkspace);

                foreach ($resourcesInstance as $resourceInstance) {
                    if ($resource->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                        $this->copyFirstCopyInstance($workspace, $resourceInstance->getId(), $parent);
                    }
                }
            } else {
                if ($resource->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                    $this->copyFirstCopyInstance($workspace, $instanceId, $parent);
                }
            }

            return new Response('copied');
        }

        return new Response('success');
    }

    /**
     * Removes a resource instance from a workspace
     *
     * @param integer $resourceId
     * @param integer $workspaceId
     *
     * @return Response
     *
     * @throws AccessDeniedException
     */
    public function removeFromWorkspaceAction($resourceId, $workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->find($workspaceId);
        $managerRole = $workspace->getManagerRole();

        if (false === $this->get('security.context')->isGranted($managerRole->getName())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($resourceId);
        $resourceType = $resourceInstance->getResourceType();
        $name = $this->findResService($resourceType);

        if ($resourceInstance->getResourceType()->getType() === 'directory') {
            $this->get($name)->delete($resourceInstance);
        } else {
            $resourceInstance->getResource()->removeResourceInstance($resourceInstance);
            $em->remove($resourceInstance);

            if (0 === $resourceInstance->getResource()->getInstanceCount()) {
                $this->get($name)->delete($resourceInstance->getResource());
            }
        }

        $em->flush();

        return new Response('success');
    }

    /**
     * Returns the resource types defined in the platform (currently only json)
     *
     * $listable can be "all", "false" and "true"
     *
     * @param string $format
     * @param string $listable
     *
     * @return Response
     */
    public function getResourceTypesAction($format, $listable)
    {
        $repo = $this->getDoctrine()->getEntityManager()->getRepository('ClarolineCoreBundle:Resource\ResourceType');
        if ($listable == 'all') {
            $resourceTypes = $repo->findAll();
        } else if ($listable == 'true') {
            $resourceTypes = $repo->findBy(array('isListable' => '1'));
        } else if ($listable == 'false') {
            $resourceTypes = $repo->findBy(array('isListable' => '0'));
        }
        $content = $this->renderView("ClarolineCoreBundle:Resource:resource_type.{$format}.twig", array('resourceTypes' => $resourceTypes));
        $response = new Response($content);

        return $response;
    }

    /**
     * Currently not in use.
     * Get the list of instance for an instance.
     *
     * @param integer $instanceId
     *
     * @return Response
     */
    public function getResourcesReferenceAction($instanceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);
        $resourcesInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->findBy(array('abstractResource' => $resourceInstance->getId()));
        $content = $this->renderView('ClarolineCoreBundle:Resource:resource_instance.json.twig', array('resourcesInstance' => $resourcesInstance));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns the license list
     * Currently not in use
     *
     * @param string $format
     *
     * @return Response
     */
    public function getJsonLicensesListAction($format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $licenses = $em->getRepository('Claroline\CoreBundle\Entity\License')->findAll();

        return $this->render("ClarolineCoreBundle:Resource:license_list.{$format}.twig", array('licenses' => $licenses));
    }

    /**
     * Returns the player list as an array.
     * Test function
     *
     * @param integer $id
     *
     * @return Response
     */
    public function getJsonPlayerListAction($id)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($id);
        $mime = $resourceInstance->getResource()->getMimeType();
        $services = $this->container->getParameter('claroline.resource_players');
        $names = array_keys($services);
        $i = 1;
        $arrayPlayer[0][0] = 'claroline.file.manager';
        $arrayPlayer[0][1] = $this->get('claroline.file.manager')->getPlayerName();

        foreach ($names as $name) {
            $srvMime = $this->get($name)->getMimeType();

            if ($mime->getName() == $srvMime || $mime->getType() == $srvMime) {
                $arrayPlayer[$i][0] = $name;
                $arrayPlayer[$i][1] = $this->get($name)->getPlayerName();
                $i++;
            }
        }

        return new Response(var_dump($arrayPlayer));
    }

    /**
     * Renders the sf2 masks lists
     *
     * @param string $format
     * @param string $type   the type of list (all | res)
     *
     * @return Response
     */
    public function getMasksAction($type, $format)
    {
        switch ($type) {
            case $res: $masks = SymfonySecurity::getResourcesMasks();
                break;
            default: $masks = SymfonySecurity::getSfMasks();
                break;
        }
        return $this->render("ClarolineCoreBundle:Resource:masks_list.{$format}.twig", array('masks' => $masks));
    }

    /**
     * Returns a copied resource instance. The resource itself is not copied.
     *
     * @param ResourceInstance $resourceInstance
     *
     * @return ResourceInstance
     */
    private function copyByReferenceResourceInstance(ResourceInstance $resourceInstance)
    {
        $ric = new ResourceInstance();
        $ric->setCreator($this->get('security.context')->getToken()->getUser());
        $ric->setCopy(true);
        $ric->setWorkspace($resourceInstance->getWorkspace());
        $ric->setResource($resourceInstance->getResource());
        $resourceInstance->getResource()->addResourceInstance($ric);

        return $ric;
    }

    /**
     * Returns a copied resource instance. The resource itself is also copied
     *
     * @param ResourceInstance $resourceInstance
     *
     * @return \Claroline\CoreBundle\Entity\Resource\ResourceInstance
     */
    private function copyByCopyResourceInstance(ResourceInstance $resourceInstance)
    {
        $user = $this->get('security.context')->getToken()->getUser();
        $ric = new ResourceInstance();
        $ric->setCreator($user);
        $ric->setCopy(false);
        $ric->setWorkspace($resourceInstance->getWorkspace());
        $name = $this->findResService($resourceInstance->getResourceType());
        $resourceCopy = $this->get($name)->copy($resourceInstance->getResource(), $user);
        $resourceCopy->setResourceType($resourceInstance->getResourceType());
        $resourceCopy->addResourceInstance($ric);
        $ric->setResource($resourceCopy);

        return $ric;
    }

    /**
     * Set the children of a copied by reference resource instance.
     *
     * @param ResourceInstance $parentInstance
     * @param AbstractWorkspace $workspace
     * @param ResourceInstance $parentCopy
     */
    private function setChildrenByReferenceCopy(ResourceInstance $parentInstance, AbstractWorkspace $workspace, ResourceInstance $parentCopy)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $children = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->children($parentInstance, true);
        $rightManager = $this->get('claroline.security.right_manager');
        $roleCollaborator = $workspace->getCollaboratorRole();

        foreach ($children as $child) {
            if ($child->getResource()->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                $copy = $this->copyByReferenceResourceInstance($child);
                $copy->setParent($parentCopy);
                $copy->setWorkspace($workspace);
                $em->persist($copy);
                $this->setChildrenByReferenceCopy($child, $workspace, $copy);
                $rightManager->addRight($copy, $roleCollaborator, MaskBuilder::MASK_VIEW);
            }
        }

        $em->flush();
    }

    /**
     * Set the children of a copied by copy resource instance
     *
     * @param ResourceInstance $parentInstance
     * @param AbstractWorkspace $workspace
     * @param ResourceInstance $parentCopy
     */
    private function setChildrenByCopyCopy(ResourceInstance $parentInstance, AbstractWorkspace $workspace, ResourceInstance $parentCopy)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $children = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->children($parentInstance, true);
        $rightManager = $this->get('claroline.security.right_manager');
        $roleCollaborator = $workspace->getCollaboratorRole();

        foreach ($children as $child) {
            if ($child->getResource()->getShareType() == AbstractResource::PUBLIC_RESOURCE) {
                $copy = $this->copyByCopyResourceInstance($child);
                $copy->setParent($parentCopy);
                $copy->setWorkspace($workspace);
                $em->persist($copy);
                $em->flush();
                $this->setChildrenByReferenceCopy($child, $workspace, $copy);
                $rightManager->addRight($copy, $roleCollaborator, MaskBuilder::MASK_VIEW);
            }
        }
    }

    /**
     * Copy a resource instance by reference and put it in a workspace.
     *
     * @param AbstractWorkspace $workspace
     * @param integer           $instanceId
     * @param ResourceInstance  $parent
     */
    private function copyFirstReferenceInstance(AbstractWorkspace $workspace, $instanceId, $parent)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $roleCollaborator = $workspace->getCollaboratorRole();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);
        $resourceInstanceCopy = $this->copyByReferenceResourceInstance($resourceInstance);
        $resourceInstanceCopy->setWorkspace($workspace);
        $resourceInstanceCopy->setParent($parent);
        $em->persist($resourceInstanceCopy);
        $resourceInstance->getResource()->addResourceInstance($resourceInstance);
        $em->flush();
        $user = $this->get('security.context')->getToken()->getUser();
        $rightManager = $this->get('claroline.security.right_manager');
        $rightManager->addRight($resourceInstanceCopy, $user, MaskBuilder::MASK_OWNER);
        $rightManager->addRight($resourceInstanceCopy, $roleCollaborator, MaskBuilder::MASK_VIEW);
        $this->setChildrenByReferenceCopy($resourceInstance, $workspace, $resourceInstanceCopy);
    }

    /**
     * Copy a resource instance by copy and put it in a workspace.
     *
     * @param AbstractWorkspace $workspace
     * @param integer           $instanceId
     * @param ResourceInstance  $parent
     */
    private function copyFirstCopyInstance(AbstractWorkspace $workspace, $instanceId, $parent)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $roleCollaborator = $workspace->getCollaboratorRole();
        $resourceInstance = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($instanceId);
        $resourceInstanceCopy = $this->copyByCopyResourceInstance($resourceInstance);
        $resourceInstanceCopy->setWorkspace($workspace);
        $resourceInstanceCopy->setParent($parent);
        $em->persist($resourceInstanceCopy);
        $em->flush();
        $user = $this->get('security.context')->getToken()->getUser();
        $rightManager = $this->get('claroline.security.right_manager');
        $rightManager->addRight($resourceInstanceCopy, $roleCollaborator, MaskBuilder::MASK_VIEW);
        $rightManager->addRight($resourceInstanceCopy, $user, MaskBuilder::MASK_OWNER);
        $this->setChildrenByCopyCopy($resourceInstance, $workspace, $resourceInstanceCopy);
    }

    /**
     * Returns the service's name for the ResourceType $resourceType
     *
     * @param ResourceType $resourceType
     *
     * @return string
     */
    private function findResService(ResourceType $resourceType)
    {
        $services = $this->container->getParameter('claroline.resource_controllers');
        $names = array_keys($services);

        foreach ($names as $name) {
            $type = $this->get($name)->getResourceType();

            if ($type == $resourceType->getType()) {
                return $name;
            }
        }
    }

}
