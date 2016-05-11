<?php
/**
 * sonntagnacht/toolbox-bundle
 * Created by PhpStorm.
 * File: AclHelper.php
 * User: con
 * Date: 18.04.16
 * Time: 12:32
 */

namespace SN\ToolboxBundle\Security;

/*
 * Copyright (C) 2011 David Mann
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * https://gist.githubusercontent.com/CodingNinja/1539784/raw/d171024bcca3a29566b13fc1bfa160cd27b76577/Manager.php
 */

use Symfony\Component\Security\Acl\Domain\Entry;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Exception\NoAceFoundException;
use Symfony\Component\Security\Acl\Model\MutableAclInterface;
use Symfony\Component\Security\Acl\Model\MutableAclProviderInterface;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Acl\Model\AclProviderInterface;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Easily work with Symfony ACL
 *
 * This class abstracts some of the ACL layer and
 * gives you very easy "Grant" and "Revoke" methods
 * which will update existing ACL's and create new ones
 * when required
 *
 * @author CodinNinja
 */
class AclHelper
{

    /**
     * @var MutableAclProviderInterface
     */
    protected $provider;

    /**
     * @var TokenStorage
     */
    protected $tokenStorage;

    /**
     * Constructor
     *
     * @param AclProviderInterface $provider
     * @param TokenStorageInterface $tokenStorage
     */
    public function __construct(AclProviderInterface $provider, TokenStorageInterface $tokenStorage)
    {
        $this->provider     = $provider;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Grant a permission
     *
     * @param mixed $entity The DomainObject to add the permissions for
     * @param integer|string $mask The initial mask
     * @param UserInterface $user (optional) the user who should be applied to
     * @return Object The original Entity
     */
    public function grant($entity, $mask = MaskBuilder::MASK_OWNER, $user = null)
    {
        $acl = $this->getAcl($entity);

        // retrieving the security identity of the currently logged-in user
        $tokenStorage  = $this->tokenStorage;
        $user             = $user instanceof UserInterface ? $user : $tokenStorage->getToken()->getUser();
        $securityIdentity = UserSecurityIdentity::fromAccount($user);

        // grant owner access
        $this->addMask($securityIdentity, $mask, $acl);

        return $entity;
    }

    /**
     * Get or create an ACL object
     *
     * @param object $entity The Domain Object to get the ACL for
     *
     * @return Acl The found / craeted ACL
     */
    protected function getAcl($entity)
    {
        // creating the ACL
        $aclProvider    = $this->provider;
        $objectIdentity = ObjectIdentity::fromDomainObject($entity);
        try {
            $acl = $aclProvider->createAcl($objectIdentity);
        } catch (\Exception $e) {
            $acl = $aclProvider->findAcl($objectIdentity);
        }

        return $acl;
    }


    /**
     * Revoke a permission
     *
     * <pre>
     *     $manager->revoke($myDomainObject, 'delete'); // Remove "delete" permission for the $myDomainObject
     * </pre>
     *
     * @param $entity
     * @param int $mask
     * @param null $user
     * @return $this
     */
    public function revoke($entity, $mask = MaskBuilder::MASK_OWNER, $user = null)
    {
        $acl  = $this->getAcl($entity);
        $aces = $acl->getObjectAces();

        $user             = $user instanceof UserInterface ? $user : $this->tokenStorage->getToken()->getUser();
        $securityIdentity = UserSecurityIdentity::fromAccount($user);

        /**
         * @var Entry $ace
         */
        foreach ($aces as $i => $ace) {
            if ($securityIdentity->equals($ace->getSecurityIdentity())) {
                $this->revokeMask($i, $acl, $ace, $mask);
            }
        }

        $this->provider->updateAcl($acl);

        return $this;
    }


    /**
     * Remove a mask
     *
     * @param $index
     * @param Acl $acl
     * @param Entry $ace
     * @param $mask
     * @return $this
     */
    protected function revokeMask($index, Acl $acl, Entry $ace, $mask)
    {
        $acl->updateObjectAce($index, $ace->getMask() & ~$mask);

        return $this;
    }

    /**
     * add a mask
     *
     * @param $securityIdentity
     * @param $mask
     * @param $acl
     * @return $this
     */
    protected function addMask(SecurityIdentityInterface $securityIdentity, $mask, MutableAclInterface $acl)
    {
        $acl->insertObjectAce($securityIdentity, $mask);
        $this->provider->updateAcl($acl);

        return $this;
    }

    /**
     * @param int|string $mask
     * @param mixed $object
     * @param UserInterface $user
     * @return bool
     */
    public function isGranted($mask, $object, UserInterface $user)
    {
        $objectIdentity   = ObjectIdentity::fromDomainObject($object);
        $securityIdentity = UserSecurityIdentity::fromAccount($user);

        try {
            $acl = $this->provider->findAcl($objectIdentity, array($securityIdentity));
        } catch (NoAceFoundException $e) {
            return false;
        }

        if (!is_int($mask)) {
            $builder = new MaskBuilder;
            $builder->add($mask);

            $mask = $builder->get();
        }

        try {
            return $acl->isGranted(array($mask), array($securityIdentity), false);
        } catch (NoAceFoundException $e) {
            return false;
        }
    }

}
