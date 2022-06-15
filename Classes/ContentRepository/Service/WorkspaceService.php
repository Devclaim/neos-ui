<?php
namespace Neos\Neos\Ui\ContentRepository\Service;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Projection\Workspace\Workspace;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\ContentRepository\NodeAccess\NodeAccessorManager;
use Neos\ContentRepository\SharedModel\NodeAddress;
use Neos\ContentRepository\SharedModel\NodeAddressFactory;
use Neos\ContentRepository\SharedModel\VisibilityConstraints;
use Neos\ContentRepository\Projection\Changes\ChangeFinder;
use Neos\ContentRepository\Projection\Content\NodeInterface;
use Neos\ContentRepository\Projection\Workspace\WorkspaceFinder;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceName;
use Neos\EventSourcedNeosAdjustments\Ui\Domain\Model\Changes\Remove;
use Neos\EventSourcedNeosAdjustments\Ui\Service\PublishingService;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\UserService;
use Neos\Neos\Domain\Service\UserService as DomainUserService;

/**
 * @Flow\Scope("singleton")
 */
class WorkspaceService
{
    /**
     * @Flow\Inject
     * @var WorkspaceFinder
     */
    protected $workspaceFinder;

    /**
     * @Flow\Inject
     * @var ChangeFinder
     */
    protected $changeFinder;

    /**
     * @Flow\Inject
     * @var NodeAccessorManager
     */
    protected $nodeAccessorManager;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var DomainUserService
     */
    protected $domainUserService;

    /**
     * @Flow\Inject
     * @var NodeAddressFactory
     */
    protected $nodeAddressFactory;

    /**
     * Get all publishable node context paths for a workspace
     *
     * @return array<int,array<string,string>>
     */
    public function getPublishableNodeInfo(WorkspaceName $workspaceName): array
    {
        $workspace = $this->workspaceFinder->findOneByName($workspaceName);
        if (is_null($workspace) || $workspace->getBaseWorkspaceName() === null) {
            return [];
        }
        $changes = $this->changeFinder->findByContentStreamIdentifier($workspace->getCurrentContentStreamIdentifier());
        $unpublishedNodes = [];
        foreach ($changes as $change) {
            if ($change->removalAttachmentPoint) {
                $nodeAddress = new NodeAddress(
                    $change->contentStreamIdentifier,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    $change->nodeAggregateIdentifier,
                    $workspaceName
                );

                /**
                 * See {@see Remove::apply} -> Removal Attachment Point == closest document node.
                 */
                $documentNodeAddress = new NodeAddress(
                    $change->contentStreamIdentifier,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    $change->removalAttachmentPoint,
                    $workspaceName
                );

                $unpublishedNodes[] = [
                    'contextPath' => $nodeAddress->serializeForUri(),
                    'documentContextPath' => $documentNodeAddress->serializeForUri()
                ];
            } else {
                $nodeAccessor = $this->nodeAccessorManager->accessorFor(
                    $workspace->getCurrentContentStreamIdentifier(),
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    VisibilityConstraints::withoutRestrictions()
                );
                $node = $nodeAccessor->findByIdentifier($change->nodeAggregateIdentifier);

                if ($node instanceof NodeInterface) {
                    $documentNode = $this->getClosestDocumentNode($node);
                    if ($documentNode instanceof NodeInterface) {
                        $unpublishedNodes[] = [
                            'contextPath' => $this->nodeAddressFactory->createFromNode($node)->serializeForUri(),
                            'documentContextPath' => $this->nodeAddressFactory->createFromNode($documentNode)
                                ->serializeForUri()
                        ];
                    }
                }
            }
        }

        return array_filter($unpublishedNodes, function ($item) {
            return (bool)$item;
        });
    }

    /**
     * Get allowed target workspaces for current user
     *
     * @return array<string,array<string,mixed>>
     */
    public function getAllowedTargetWorkspaces(): array
    {
        $user = $this->domainUserService->getCurrentUser();

        $workspacesArray = [];
        /** @var Workspace $workspace */
        foreach ($this->workspaceFinder->findAll() as $workspace) {
            // FIXME: This check should be implemented through a specialized Workspace Privilege or something similar
            // Skip workspace not owned by current user
            if ($workspace->getWorkspaceOwner() !== null && $workspace->getWorkspaceOwner() !== $user) {
                continue;
            }
            // Skip own personal workspace
            if ($workspace === $this->userService->getPersonalWorkspace()) {
                continue;
            }

            if ($workspace->isPersonalWorkspace()) {
                // Skip other personal workspaces
                continue;
            }

            $workspaceArray = [
                'name' => $workspace->getWorkspaceName()->jsonSerialize(),
                'title' => $workspace->getWorkspaceTitle()->jsonSerialize(),
                'description' => $workspace->getWorkspaceDescription()->jsonSerialize(),
                'readonly' => !$this->domainUserService->currentUserCanPublishToWorkspace($workspace)
            ];
            $workspacesArray[$workspace->getWorkspaceName()->jsonSerialize()] = $workspaceArray;
        }

        return $workspacesArray;
    }

    /**
     * Sets base workspace of current user workspace
     *
     * @param Workspace $workspace
     * @return void
     */
    public function setBaseWorkspace(Workspace $workspace)
    {
        $userWorkspace = $this->userService->getPersonalWorkspace();
        $userWorkspace->setBaseWorkspace($workspace);
    }

    private function getClosestDocumentNode(NodeInterface $node): ?NodeInterface
    {
        $nodeAccessor = $this->nodeAccessorManager->accessorFor(
            $node->getContentStreamIdentifier(),
            $node->getDimensionSpacePoint(),
            $node->getVisibilityConstraints()
        );

        while ($node instanceof NodeInterface) {
            if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
                return $node;
            }
            $node = $nodeAccessor->findParentNode($node);
        }

        return null;
    }
}
