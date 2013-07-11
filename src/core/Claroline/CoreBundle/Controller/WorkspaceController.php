<?php

namespace Claroline\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Claroline\CoreBundle\Event\StrictDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace;
use Claroline\CoreBundle\Library\Workspace\Configuration;
use Claroline\CoreBundle\Event\Event\DisplayToolEvent;
use Claroline\CoreBundle\Event\Event\DisplayWidgetEvent;
use Claroline\CoreBundle\Event\Event\Log\LogWorkspaceToolReadEvent;
use Claroline\CoreBundle\Event\Event\Log\LogWorkspaceDeleteEvent;
use Claroline\CoreBundle\Form\Factory\FormFactory;
use Claroline\CoreBundle\Library\Security\Utilities;
use Claroline\CoreBundle\Manager\RoleManager;
use Claroline\CoreBundle\Manager\WorkspaceManager;
use Claroline\CoreBundle\Manager\WorkspaceTagManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use JMS\DiExtraBundle\Annotation as DI;

/**
 * This controller is able to:
 * - list/create/delete/show workspaces.
 * - return some users/groups list (ie: (un)registered users to a workspace).
 * - add/delete users/groups to a workspace.
 */
class WorkspaceController extends Controller
{
    private $workspaceManager;
    private $roleManager;
    private $tagManager;
    private $eventDispatcher;
    private $security;
    private $router;
    private $utils;
    private $formFactory;

    /**
     * @DI\InjectParams({
     *     "workspaceManager"   = @DI\Inject("claroline.manager.workspace_manager"),
     *     "roleManager"        = @DI\Inject("claroline.manager.role_manager"),
     *     "tagManager"         = @DI\Inject("claroline.manager.workspace_tag_manager"),
     *     "eventDispatcher"    = @DI\Inject("claroline.event.event_dispatcher"),
     *     "security"           = @DI\Inject("security.context"),
     *     "router"             = @DI\Inject("router"),
     *     "utils"              = @DI\Inject("claroline.security.utilities"),
     *     "formFactory"        = @DI\Inject("claroline.form.factory")
     * })
     */
    public function __construct(
        WorkspaceManager $workspaceManager,
        RoleManager $roleManager,
        WorkspaceTagManager $tagManager,
        StrictDispatcher $eventDispatcher,
        SecurityContextInterface $security,
        UrlGeneratorInterface $router,
        Utilities $utils,
        FormFactory $formFactory
    )
    {
        $this->workspaceManager = $workspaceManager;
        $this->roleManager = $roleManager;
        $this->tagManager = $tagManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->security = $security;
        $this->router = $router;
        $this->utils= $utils;
        $this->formFactory = $formFactory;
    }

    /**
     * @EXT\Route(
     *     "/",
     *     name="claro_workspace_list",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @EXT\Template()
     *
     * Renders the workspace list page with its claroline layout.
     *
     * @return Response
     */
    public function listAction()
    {
        $datas = $this->tagManager->getDatasForWorkspaceList(false);

        return array(
            'workspaces' => $datas['workspaces'],
            'tags' => $datas['tags'],
            'tagWorkspaces' => $datas['tagWorkspaces'],
            'hierarchy' => $datas['hierarchy'],
            'rootTags' => $datas['rootTags'],
            'displayable' => $datas['displayable']
        );
    }

    /**
     * @EXT\Route(
     *     "/user",
     *     name="claro_workspace_by_user",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @EXT\Template()
     *
     * Renders the registered workspace list for a user.
     *
     * @return Response
     */
    public function listWorkspacesByUserAction()
    {
        $this->assertIsGranted('ROLE_USER');
        $em = $this->get('doctrine.orm.entity_manager');
        $token = $this->security->getToken();
        $user = $token->getUser();
        $roles = $this->utils->getRoles($token);
        $workspaces = $this->workspaceManager->getWorkspacesByRoles($roles);
        $tags = $this->tagManager->getNonEmptyTagsByUser($user);
        $relTagWorkspace = $this->tagManager->getTagRelationsByUser($user);
        $tagWorkspaces = array();

        foreach ($relTagWorkspace as $tagWs) {

            if (empty($tagWorkspaces[$tagWs['tag_id']])) {
                $tagWorkspaces[$tagWs['tag_id']] = array();
            }
            $tagWorkspaces[$tagWs['tag_id']][] = $tagWs['rel_ws_tag'];
        }
        $tagsHierarchy = $this->tagManager->getAllHierarchiesByUser($user);
        $rootTags = $this->tagManager->getRootTags($user);
        $hierarchy = array();

        // create an array : tagId => [direct_children_id]
        foreach ($tagsHierarchy as $tagHierarchy) {

            if ($tagHierarchy->getLevel() === 1) {

                if (!isset($hierarchy[$tagHierarchy->getParent()->getId()]) ||
                    !is_array($hierarchy[$tagHierarchy->getParent()->getId()])) {

                    $hierarchy[$tagHierarchy->getParent()->getId()] = array();
                }
                $hierarchy[$tagHierarchy->getParent()->getId()][] = $tagHierarchy->getTag();
            }
        }

        // create an array indicating which tag is displayable
        // a tag is displayable if it or one of his children contains is associated to a workspace
        $displayable = array();
        $allTags = $this->tagManager->getTagsByUser($user);

        foreach ($allTags as $oneTag) {
            $oneTagId = $oneTag->getId();
            $displayable[$oneTagId] = $this->isTagDisplayable($oneTagId, $tagWorkspaces, $hierarchy);
        }

        return array(
            'user' => $user,
            'workspaces' => $workspaces,
            'tags' => $tags,
            'tagWorkspaces' => $tagWorkspaces,
            'hierarchy' => $hierarchy,
            'rootTags' => $rootTags,
            'displayable' => $displayable
        );
    }

    private function isTagDisplayable($tagId, array $tagWorkspaces, array $hierarchy)
    {
        $displayable = false;

        if (isset($tagWorkspaces[$tagId]) && count($tagWorkspaces[$tagId]) > 0) {
            $displayable = true;
        } else {

            if (isset($hierarchy[$tagId]) && count($hierarchy[$tagId]) > 0) {
                $children = $hierarchy[$tagId];

                foreach ($children as $child) {

                    $displayable = $this->isTagDisplayable($child->getId(), $tagWorkspaces, $hierarchy);

                    if ($displayable) {
                        break;
                    }
                }
            }
        }

        return $displayable;
    }

    /**
     * @EXT\Route(
     *     "/new/form",
     *     name="claro_workspace_creation_form"
     * )
     * @EXT\Method("GET")
     *
     * @EXT\Template()
     *
     * Renders the workspace creation form.
     *
     * @return Response
     */
    public function creationFormAction()
    {
        $this->assertIsGranted('ROLE_WS_CREATOR');
        $form = $this->formFactory->create(FormFactory::TYPE_WORKSPACE);

        return array('form' => $form->createView());
    }

    /**
     * Creates a workspace from a form sent by POST.
     *
     * @EXT\Route(
     *     "/",
     *     name="claro_workspace_create"
     * )
     * @EXT\Method("POST")
     *
     * @EXT\Template("ClarolineCoreBundle:Workspace:creationForm.html.twig")
     *
     * @return RedirectResponse
     */
    public function createAction()
    {
        $this->assertIsGranted('ROLE_WS_CREATOR');
        $form = $this->formFactory->create(FormFactory::TYPE_WORKSPACE);
        $form->handleRequest($this->getRequest());

        $templateDir = $this->container->getParameter('claroline.param.templates_directory');
        $ds = DIRECTORY_SEPARATOR;

        if ($form->isValid()) {
            $type = $form->get('type')->getData() == 'simple' ?
                Configuration::TYPE_SIMPLE :
                Configuration::TYPE_AGGREGATOR;
            $config = Configuration::fromTemplate($templateDir.$ds.$form->get('template')->getData()->getHash());
            $config->setWorkspaceType($type);
            $config->setWorkspaceName($form->get('name')->getData());
            $config->setWorkspaceCode($form->get('code')->getData());
            $user = $this->security->getToken()->getUser();
            $this->workspaceManager->create($config, $user);
            $this->get('claroline.security.token_updater')->update($this->security->getToken());
            $route = $this->router->generate('claro_workspace_list');

            return new RedirectResponse($route);
        }

        return array('form' => $form->createView());
    }

    /**
     * @EXT\Route(
     *     "/{workspaceId}",
     *     name="claro_workspace_delete",
     *     options={"expose"=true},
     *     requirements={"workspaceId"="^(?=.*[1-9].*$)\d*$"}
     * )
     * @EXT\Method("DELETE")
     * @EXT\ParamConverter(
     *      "workspace",
     *      class="ClarolineCoreBundle:Workspace\AbstractWorkspace",
     *      options={"id" = "workspaceId", "strictId" = true}
     * )
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function deleteAction(AbstractWorkspace $workspace)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $this->assertIsGranted('DELETE', $workspace);
        $this->eventDispatcher->dispatch(
            'log',
            'Log\LogWorkspaceDelete',
            array($workspace)
        );
        $em->remove($workspace);
        $em->flush();

        return new Response('success', 204);
    }

    /**
     * @EXT\ParamConverter(
     *      "workspace",
     *      class="ClarolineCoreBundle:Workspace\AbstractWorkspace",
     *      options={"id" = "workspaceId", "strictId" = true}
     * )
     * @EXT\Template()
     *
     * Renders the left tool bar. Not routed.
     *
     * @param $_workspace
     *
     * @return Response
     */
    public function renderToolListAction(AbstractWorkspace $workspace, $_breadcrumbs)
    {
        $em = $this->get('doctrine.orm.entity_manager');

        if ($_breadcrumbs != null) {
            //for manager.js, id = 0 => "no root".
            if ($_breadcrumbs[0] != 0) {
                $rootId = $_breadcrumbs[0];
            } else {
                $rootId = $_breadcrumbs[1];
            }
            $workspace = $em->getRepository('ClarolineCoreBundle:Resource\AbstractResource')
                ->find($rootId)->getWorkspace();
        }

        if (!$this->security->isGranted('OPEN', $workspace)) {
            throw new AccessDeniedException();
        }

        $currentRoles = $this->utils->getRoles($this->security->getToken());

        $orderedTools = $em->getRepository('ClarolineCoreBundle:Tool\OrderedTool')
            ->findByWorkspaceAndRoles($workspace, $currentRoles);

        return array(
            'orderedTools' => $orderedTools,
            'workspace' => $workspace
        );
    }

    /**
     * @EXT\Route(
     *     "/{workspaceId}/open/tool/{toolName}",
     *     name="claro_workspace_open_tool",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     * @EXT\ParamConverter(
     *      "workspace",
     *      class="ClarolineCoreBundle:Workspace\AbstractWorkspace",
     *      options={"id" = "workspaceId", "strictId" = true}
     * )
     *
     * Opens a tool.
     *
     * @param type $toolName
     * @param type $workspaceId
     *
     * @return Response
     */
    public function openToolAction($toolName, AbstractWorkspace $workspace)
    {
        $this->assertIsGranted($toolName, $workspace);
        $event = $this->eventDispatcher->dispatch(
            'open_tool_workspace_'.$toolName,
            'DisplayTool',
            array($workspace)
        );
        $this->eventDispatcher->dispatch(
            'log',
            'Log\LogWorkspaceToolRead',
            array($workspace,$toolName)
        );

        return new Response($event->getContent());
    }

    /**
     * @EXT\Route(
     *     "/{workspaceId}/widgets",
     *     name="claro_workspace_widgets"
     * )
     * @EXT\Method("GET")
     * @EXT\ParamConverter(
     *      "workspace",
     *      class="ClarolineCoreBundle:Workspace\AbstractWorkspace",
     *      options={"id" = "workspaceId", "strictId" = true}
     * )
     *
     * @EXT\Template("ClarolineCoreBundle:Widget:widgets.html.twig")
     *
     * Display registered widgets.
     *
     * @param integer $workspaceId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @todo Reduce the number of sql queries for this action (-> dql)
     */
    public function widgetsAction(AbstractWorkspace $workspace)
    {
        // No right checking is done : security is delegated to each widget renderer
        // Is that a good idea ?
        $configs = $this->get('claroline.widget.manager')
            ->generateWorkspaceDisplayConfig($workspace->getId());

        $rightToConfigure = $this->security->isGranted('parameters', $workspace);

        $widgets = array();

        foreach ($configs as $config) {
            if ($config->isVisible()) {
                $eventName = "widget_{$config->getWidget()->getName()}_workspace";
                //$event = new DisplayWidgetEvent($workspace);
                $event = $this->eventDispatcher->dispatch($eventName, 'DisplayWidget', array($workspace));

                if ($event->hasContent()) {
                    $widget['id'] = $config->getWidget()->getId();
                    if ($event->hasTitle()) {
                        $widget['title'] = $event->getTitle();
                    } else {
                        $widget['title'] = strtolower($config->getWidget()->getName());
                    }
                    $widget['content'] = $event->getContent();
                    $widget['configurable'] = (
                        $rightToConfigure
                        and $config->isLocked() !== true
                        and $config->getWidget()->isConfigurable()
                    );

                    $widgets[] = $widget;
                }
            }
        }

        return array(
            'widgets' => $widgets,
            'isDesktop' => false,
            'workspaceId' => $workspace->getId()
        );
    }

    /**
     * @EXT\Route(
     *     "/{workspaceId}/open",
     *     name="claro_workspace_open"
     * )
     * @EXT\Method("GET")
     * @EXT\ParamConverter(
     *      "workspace",
     *      class="ClarolineCoreBundle:Workspace\AbstractWorkspace",
     *      options={"id" = "workspaceId", "strictId" = true}
     * )
     *
     * Open the first tool of a workspace.
     *
     * @param integer $workspaceId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function openAction(AbstractWorkspace $workspace)
    {
        $em = $this->getDoctrine()->getManager();

        if ('anon.' != $this->get('security.context')->getToken()->getUser()) {
            $roles = $this->roleManager->getRolesByWorkspace($workspace);
            $foundRole = null;

            foreach ($roles as $wsRole) {
                foreach ($this->security->getToken()->getUser()->getRoles() as $userRole) {
                    if ($userRole == $wsRole->getName()) {
                        $foundRole = $userRole;
                    }
                }
            }

            $isAdmin = $this->security->getToken()->getUser()->hasRole('ROLE_ADMIN');

            if ($foundRole === null && !$isAdmin) {
                throw new AccessDeniedException('No role found in that workspace');
            }

            if ($isAdmin) {
                //admin always open the home.
                $openedTool = $em->getRepository('ClarolineCoreBundle:Tool\Tool')
                    ->findBy(array('name' => 'home'));
            } else {
                $openedTool = $em->getRepository('ClarolineCoreBundle:Tool\Tool')
                    ->findDisplayedByRolesAndWorkspace(array($foundRole), $workspace);
            }

        } else {
            $foundRole = 'ROLE_ANONYMOUS';
            $openedTool = $em->getRepository('ClarolineCoreBundle:Tool\Tool')
                ->findDisplayedByRolesAndWorkspace(array('ROLE_ANONYMOUS'), $workspace);
        }

        if ($openedTool == null) {
            throw new AccessDeniedException("No tool found for role {$foundRole}");
        }

        $route = $this->router->generate(
            'claro_workspace_open_tool',
            array('workspaceId' => $workspace->getId(), 'toolName' => $openedTool[0]->getName())
        );

        return new RedirectResponse($route);
    }

    /**
     * @EXT\Route(
     *     "/search/role/code/{code}",
     *     name="claro_resource_find_role_by_code",
     *     options={"expose"=true}
     * )
     */
    public function findRoleByWorkspaceCodeAction($code)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $roles = $em->getRepository('ClarolineCoreBundle:Role')->findByWorkspaceCodeTag($code);
        $arWorkspace = array();

        foreach ($roles as $role) {
            $arWorkspace[$role->getWorkspace()->getCode()][$role->getName()] = array(
                'name' => $role->getName(),
                'translation_key' => $role->getTranslationKey(),
                'id' => $role->getId(),
                'workspace' => $role->getWorkspace()->getName()
            );
        }

        return new JsonResponse($arWorkspace);
    }

    private function assertIsGranted($attributes, $object = null)
    {
        if (false === $this->security->isGranted($attributes, $object)) {
            throw new AccessDeniedException();
        }
    }
}