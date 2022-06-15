<?php
namespace Neos\Neos\Ui\Fusion\Helper;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\ContentRepository\Projection\Content\ContentSubgraphInterface;
use Neos\ContentRepository\Projection\Workspace\Workspace;
use Neos\ContentRepository\Projection\Workspace\WorkspaceFinder;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\Model\WorkspaceName as NeosWorkspaceName;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\ContentRepository\Service\WorkspaceService;

/**
 * The Workspace helper for EEL contexts
 */
class WorkspaceHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var WorkspaceFinder
     */
    protected $workspaceFinder;

    /**
     * @Flow\Inject
     * @var WorkspaceService
     */
    protected $workspaceService;

    /**
     * @Flow\Inject
     * @var DomainUserService
     */
    protected $domainUserService;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;


    /**
     * @param ContentSubgraphInterface $contentSubgraph
     * @return array|Workspace[]
     */
    public function getWorkspaceChain(?ContentSubgraphInterface $contentSubgraph): array
    {
        if ($contentSubgraph === null) {
            return [];
        }

        /** @var Workspace $currentWorkspace */
        $currentWorkspace = $this->workspaceFinder->findOneByCurrentContentStreamIdentifier(
            $contentSubgraph->getContentStreamIdentifier()
        );
        $workspaceChain = [];
        // TODO: Maybe write CTE here
        while ($currentWorkspace instanceof Workspace) {
            $workspaceChain[(string)$currentWorkspace->getWorkspaceName()] = $currentWorkspace;
            $currentWorkspace = $currentWorkspace->getBaseWorkspaceName()
                ? $this->workspaceFinder->findOneByName($currentWorkspace->getBaseWorkspaceName())
                : null;
        }

        return $workspaceChain;
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getPublishableNodeInfo(WorkspaceName $workspaceName): array
    {
        return $this->workspaceService->getPublishableNodeInfo($workspaceName);
    }

    public function getAllowedTargetWorkspaces()
    {
        return $this->workspaceService->getAllowedTargetWorkspaces();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPersonalWorkspace(): array
    {
        $currentAccount = $this->securityContext->getAccount();
        $personalWorkspaceName = NeosWorkspaceName::fromAccountIdentifier(
            $currentAccount->getAccountIdentifier()
        )->toContentRepositoryWorkspaceName();
        $personalWorkspace = $this->workspaceFinder->findOneByName($personalWorkspaceName);

        return !is_null($personalWorkspace)
            ? [
                'name' => $personalWorkspace->getWorkspaceName(),
                'publishableNodes' => $this->getPublishableNodeInfo($personalWorkspaceName),
                'baseWorkspace' => $personalWorkspace->getBaseWorkspaceName(),
                // TODO: FIX readonly flag!
                //'readOnly' => !$this->domainUserService->currentUserCanPublishToWorkspace($baseWorkspace)
                'readOnly' => false
            ]
            : [];
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return bool
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
