<?php

declare(strict_types=1);

namespace Packeton\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;
use Packeton\Security\Acl\PackagesAclChecker;
use Symfony\Component\Routing\RouterInterface;

class InMemoryDumper
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PackagesAclChecker $checker,
        private readonly RouterInterface $router
    ) {}

    /**
     * @param User|null $user
     * @return array
     */
    public function dump(User $user = null): array
    {
        return $this->dumpRootPackages($user);
    }

    /**
     * @param null|User $user
     * @param string|Package $package
     * @param array $versionData
     *
     * @return array
     */
    public function dumpPackage(?User $user, $package, array $versionData = null): array
    {
        if (is_string($package)) {
            $package = $this->registry
                ->getRepository(Package::class)
                ->findOneBy(['name' => $package]);
        }

        if (!$package instanceof Package) {
            return [];
        }

        if ($user !== null && $this->checker->isGrantedAccessForPackage($user, $package) === false) {
            return [];
        }

        $versionIds = $packageData = [];
        /** @var Version $version */
        foreach ($package->getVersions() as $version) {
            if ($user === null || $this->checker->isGrantedAccessForVersion($user, $version)) {
                $versionIds[$version->getId()] = $version;
            }
        }

        $versionRepo = $this->registry->getRepository(Version::class);
        $versionData = $versionData === null ? $versionRepo->getVersionData(\array_keys($versionIds)) : $versionData;
        foreach ($versionIds as $version) {
            $packageData[$version->getVersion()] = \array_merge(
                $version->toArray($versionData),
                ['uid' => $version->getId()]
            );
        }

        return $packageData;
    }

    private function dumpRootPackages(User $user = null)
    {
        [$providers, $packagesData, $availablePackages] = $this->dumpUserPackages($user);

        $rootFile = ['packages' => []];
        $url = $this->router->generate('track_download', ['name' => 'VND/PKG']);
        $rootFile['notify'] = str_replace('VND/PKG', '%package%', $url);
        $rootFile['notify-batch'] = $this->router->generate('track_download_batch');
        $rootFile['providers-url'] = '/p/%package%$%hash%.json';

        $rootFile['metadata-url'] = '/p2/%package%.json';
        $rootFile['available-packages'] = $availablePackages;

        $userHash = \hash('sha256', \json_encode($providers));
        $rootFile['provider-includes'] = [
            'p/providers$%hash%.json' => [
                'sha256' => $userHash
            ]
        ];

        return [$rootFile, $providers, $packagesData];
    }

    private function dumpUserPackages(User $user = null): array
    {
        $packages = $user ?
            $this->registry->getRepository(Group::class)
                ->getAllowedPackagesForUser($user) :
            $this->registry->getRepository(Package::class)->findAll();

        $providers = $packagesData = [];
        $versionData = $this->getVersionData($packages);
        $availablePackages = array_map(fn(Package $pkg) => $pkg->getName(), $packages);

        foreach ($packages as $package) {
            if (!$packageData = $this->dumpPackage($user, $package, $versionData)) {
                continue;
            }

            $packageData = [
                'packages' => [$package->getName() => $packageData]
            ];
            $packagesData[$package->getName()] = $packageData;
            $providers[$package->getName()] = [
                'sha256' => \hash('sha256', \json_encode($packageData))
            ];
        }

        return [['providers' => $providers], $packagesData, $availablePackages];
    }


    private function getVersionData(array $packages)
    {
        $allPackagesIds = array_map(fn(Package $pkg) => $pkg->getId(), $packages);

        $repo = $this->registry->getRepository(Version::class);

        $allVersionsIds = $repo
            ->createQueryBuilder('v')
            ->resetDQLPart('select')
            ->select('v.id')
            ->where('IDENTITY(v.package) IN (:ids)')
            ->setParameter('ids', $allPackagesIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $repo->getVersionData($allVersionsIds);
    }
}