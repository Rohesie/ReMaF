<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\Soldier;
use App\Entity\Unit;
use App\Form\AreYouSureType;
use App\Form\CharacterSelectType;
use App\Form\SoldiersRecruitType;
use App\Form\UnitRebaseType;
use App\Form\UnitSettingsType;
use App\Form\UnitSoldiersType;
use App\Service\AppState;
use App\Service\Economy;
use App\Service\GameRequestManager;
use App\Service\Generator;
use App\Service\History;
use App\Service\MilitaryManager;

use App\Service\PermissionManager;
use App\Service\Dispatcher\UnitDispatcher;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class UnitController extends AbstractController
{

        private EntityManagerInterface $em;
        private History $hist;
        private MilitaryManager $mm;
        private PermissionManager $pm;
        private TranslatorInterface $trans;
        private UnitDispatcher $ud;

        public function __construct(EntityManagerInterface $em, History $hist, MilitaryManager $mm, PermissionManager $pm, TranslatorInterface $trans, UnitDispatcher $ud)
        {
                $this->em = $em;
                $this->hist = $hist;
                $this->mm = $mm;
                $this->pm = $pm;
                $this->trans = $trans;
                $this->ud = $ud;
        }

        private function findUnits(Character $character)
        {
                $em = $this->em;
                $pm = $this->pm;
                $settlement = $character->getInsideSettlement();
                if ($settlement && ($pm->checkSettlementPermission($settlement, $character, 'units'))) {
                        $query = $em->createQuery('SELECT u FROM BM2SiteBundle:Unit u JOIN BM2SiteBundle:UnitSettings s WHERE (u.character = :char OR u.settlement = :settlement OR (u.marshal = :char AND u.settlement = :settlement)) AND (u.disbanded IS NULL or u.disbanded = false) ORDER BY s.name ASC');
                        $query->setParameters(array('char' => $character, 'settlement' => $character->getInsideSettlement()));
                } elseif ($character->getInsideSettlement()) {
                        $query = $em->createQuery('SELECT u FROM BM2SiteBundle:Unit u JOIN BM2SiteBundle:UnitSettings s WHERE (u.character = :char OR (u.marshal = :char AND u.settlement = :settlement)) AND (u.disbanded IS NULL or u.disbanded = false) ORDER BY s.name ASC');
                        $query->setParameters(array('char' => $character, 'settlement' => $character->getInsideSettlement()));
                } else {
                        $query = $em->createQuery('SELECT u FROM BM2SiteBundle:Unit u JOIN BM2SiteBundle:UnitSettings s WHERE u.character = :char AND (u.disbanded IS NULL or u.disbanded = false) ORDER BY s.name ASC');
                        $query->setParameter('char', $character);
                }
                return $query->getResult();
        }

        private function findMarshalledUnits(Character $character)
        {
                $em = $this->em;
                if ($character->getInsideSettlement()) {
                        $query = $em->createQuery('SELECT u FROM BM2SiteBundle:Unit u JOIN BM2SiteBundle:UnitSettings s WHERE u.marshal = :char AND u.settlement = :settlement ORDER BY s.name ASC');
                        $query->setParameters(array('char' => $character, 'settlement' => $character->getInsideSettlement()));
                        return $query->getResult();
                } else {
                        return null;
                }
        }

        private function gateway($test, $secondary = null, $settlement = false)
        {
                return $this->ud->gateway($test, $settlement, true, false, $secondary);
        }

        #[Route('/units', name: 'maf_units')]
        public function indexAction(): RedirectResponse|Response
        {
                $character = $this->gateway('personalAssignedUnitsTest');
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $pm = $this->pm;
                $all = $this->findUnits($character);
                $units = [];
                foreach ($all as $each) {
                        $id = $each->getId();
                        $units[$id] = [];
                        $units[$id]['obj'] = $each;
                        $settlement = $each->getSettlement();
                        $units[$id]['settlement'] = $settlement;
                        if (!$settlement || $pm->checkSettlementPermission($settlement, $character, 'units')) {
                                $units[$id]['owner'] = true;
                        } else {
                                $units[$id]['owner'] = false;
                        }
                        if ($settlement) {
                                $units[$id]['base'] = true;
                        } else {
                                $units[$id]['base'] = false;
                        }
                        if ($each->getMarshal() == $character) {
                                $units[$id]['marshal'] = true;
                        } else {
                                $units[$id]['marshal'] = false;
                        }
                        if ($each->getCharacter() == $character) {
                                $units[$id]['mine'] = true;
                        } else {
                                $units[$id]['mine'] = false;
                        }
                }

                return $this->render('Unit/units.html.twig', [
                        'units' => $units,
                        'character' => $character
                ]);
        }

        #[Route('/units/{unit}', name: 'maf_unit_info', requirements: ['unit' => '\d+'])]
        public function infoAction(Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitInfoTest');
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                return $this->render('Unit/info.html.twig', [
                        'unit' => $unit,
                        'char' => $character
                ]);
        }

        /**
         * @Route("/units/new", name="maf_unit_new")
         */

        #[Route('/units/new}', name: 'maf_unit_new')]
        public function createAction(GameRequestManager $gm, Request $request): RedirectResponse|Response
        {
                $character = $this->gateway('unitNewTest');
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $settlements = $gm->getAvailableFoodSuppliers($character);
                $here = $character->getInsideSettlement()->getId();
                if (!in_array($here, $settlements)) {
                        $settlements[] = $here;
                }

                $form = $this->createForm(UnitSettingsType::class, null, ['supply' => true, 'settlements' => $settlements, 'settings' => null, 'lord' => true]);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        if (in_array($data['supplier']->getId(), $settlements)) {
                                $this->mm->newUnit($character, $character->getInsideSettlement(), $data);
                                $this->addFlash('notice', $this->trans->trans('unit.manage.created', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.manage.supplierinvalid', [], 'actions'));
                        }
                }

                return $this->render('Unit/create.html.twig', [
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/manage', name: 'maf_unit_manage', requirements: ['unit' => '\d+'])]
        public function unitManageAction(GameRequestManager $gm, Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitManageTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                /*
                Character -> Lead units
                Lord -> Local units and lead units
                */
                $lord = false;
                $settlement = $unit->getSettlement();
                $inside = $character->getInsideSettlement();
                if ($inside) {
                        if (($character === $character->getInsideSettlement()->getOwner() || $character === $character->getInsideSettlement()->getSteward()) || ($inside === $settlement && $this->pm->checkSettlementPermission($inside, $character, 'units'))) {
                                $lord = true;
                        }
                }


                $settlements = $gm->getAvailableFoodSuppliers($character);
                $supplier = $unit->getSupplier();
                if ($supplier) {
                        if (!in_array($supplier->getId(), $settlements)) {
                                $settlements[] = $supplier->getId();
                        }
                }
                if ($settlement && $lord) {
                        $settlements[] = $settlement->getId();
                }

                $form = $this->createForm(UnitSettingsType::class, null, ['supply' => true, 'settlements' => $settlements, 'settings' => null, 'lord' => true]);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        $success = $this->mm->updateSettings($unit, $data, $character, $lord);
                        if ($success) {
                                $this->addFlash('notice', $this->trans->trans('unit.manage.success', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.manage.failed', array(), 'actions'));
                        }
                }

                return $this->render('Unit/manage.html.twig', [
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/soldiers', name: 'maf_unit_soldiers', requirements: ['unit' => '\d+'])]
        public function unitSoldiersAction(AppState $app, Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitSoldiersTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $settlement = $character->getInsideSettlement();
                $resupply = array();
                $training = array();
                $units = false;
                $canResupply = false;
                $canRecruit = false;
                $canReassign = false;
                $hasUnitsPerm = false;

                if ($settlement) {
                        # If we can manage units, we can reassign and resupply. Build the list.
                        if ($this->pm->checkSettlementPermission($settlement, $character, 'units')) {
                                foreach ($settlement->getUnits() as $each) {
                                        if (!$each->getCharacter() && !$each->getPlace() && !$each->getDisbanded()) {
                                                $units[] = $each;
                                        }
                                }
                                if ($unit->getSettlement() === $settlement) {
                                        $hasUnitsPerm = true;
                                        $canRecruit = true;
                                        $training = $this->mm->findAvailableEquipment($settlement, true);
                                }
                                $canResupply = true;
                                $resupply = $this->mm->findAvailableEquipment($settlement, false);
                                $canReassign = true;
                        }

                        # If the unit has a settlement and either they are commanded by someone or not under anyones command (and thus in it).
                        if (!$canResupply && ($settlement === $unit->getSettlement() || $this->pm->checkSettlementPermission($settlement, $character, 'resupply'))) {
                                $canResupply = true;
                                $resupply = $this->mm->findAvailableEquipment($settlement, false);
                        }
                        if (!$canRecruit && ($unit->getSettlement() === $settlement && $this->pm->checkSettlementPermission($settlement, $character, 'recruit'))) {
                                $canRecruit = true;
                                $training = $this->mm->findAvailableEquipment($settlement, true);
                        }
                } else {
                        foreach ($character->getEntourage() as $entourage) {
                                if ($entourage->getEquipment()) {
                                        $item = $entourage->getEquipment()->getId();
                                        if (!isset($resupply[$item])) {
                                                $resupply[$item] = array('item' => $entourage->getEquipment(), 'resupply' => 0);
                                        }
                                        $resupply[$item]['resupply'] += $entourage->getSupply();
                                }
                        }
                }

                # Check if we can also handle our own units.
                foreach ($character->getUnits() as $mine) {
                        if (!$mine->getSettlement() || ($mine->getSettlement() && $this->pm->checkSettlementPermission($mine->getSettlement(), $character, 'units'))) {
                                $units[] = $mine;
                                if ($mine === $unit) {
                                        $canReassign = true;
                                }
                        }
                }

                if (!$canReassign && count($units) > 0 && $units[0] !== $unit && $unit->getMarshal() === $character) {
                        $canReassign = true;
                }
                $form = $this->createForm(UnitSoldiersType::class, null, [
                        'soldiers' => $unit->getNotRecruits(),
                        'available_resupply' => $resupply,
                        'available_training' => $training,
                        'others' => $units,
                        'reassign' => $canReassign,
                        'unit' => $unit,
                        'hasUnitPerm' => $hasUnitsPerm,
                ]);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();

                        $this->mm->manageUnit($unit->getSoldiers(), $data, $settlement, $character, $canResupply, $canRecruit, $canReassign);
                        // TODO: notice with result

                        $em = $this->em;
                        $em->flush();
                        $app->setSessionData($character); // update, because maybe we changed our soldiers count
                        return $this->redirect($request->getUri());
                }

                return $this->render('Unit/soldiers.html.twig', [
                        'soldiers' => $unit->getNotRecruits(),
                        'recruits' => $unit->getRecruits(),
                        'resupply' => $resupply,
                        'training' => $training,
                        'form' => $form->createView(),
                        'unit' => $unit,
                ]);
        }

        #[Route('/units/{unit}/cancel/{recruit}', name: 'maf_unit_recruit_cancel', requirements: ['unit' => '\d+', 'recruit' => '\d+'])]
        public function cancelTrainingAction(Unit $unit, Soldier $recruit): RedirectResponse
        {
                list($character, $settlement) = $this->gateway('unitSoldiersTest', $unit, true);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                $em = $this->em;
                if (!$recruit->isRecruit()) {
                        throw $this->createNotFoundException('error.notfound.recruit');
                }

                // return his equipment to the stockpile:
                $check = false;
                if ($recruit->getOldWeapon() && $recruit->getWeapon() !== $recruit->getOldWeapon()) {
                        $check = true;
                        $this->mm->returnItem($settlement, $recruit->getWeapon());
                }
                if ($recruit->getOldArmour() && $recruit->getArmour() !== $recruit->getOldArmour()) {
                        $check = true;
                        $this->mm->returnItem($settlement, $recruit->getArmour());
                }
                if ($recruit->getOldEquipment() && $recruit->getEquipment() !== $recruit->getOldEquipment()) {
                        $check = true;
                        $this->mm->returnItem($settlement, $recruit->getEquipment());
                }
                if ($recruit->getOldMount() && $recruit->getMount() !== $recruit->getOldMount()) {
                        $check = true;
                        $this->mm->returnItem($settlement, $recruit->getMount());
                }

                if ($check) {
                        // old soldier - return to militia with his old stuff
                        $recruit->setWeapon($recruit->getOldWeapon());
                        $recruit->setArmour($recruit->getOldArmour());
                        $recruit->setEquipment($recruit->getOldEquipment());
                        $recruit->setMount($recruit->getOldMount());
                        $recruit->setTraining(0)->setTrainingRequired(0);
                        $this->hist->addToSoldierLog($recruit, 'traincancel');
                } else {
                        // fresh recruit - return to workforce
                        $this->mm->returnItem($settlement, $recruit->getWeapon());
                        $this->mm->returnItem($settlement, $recruit->getArmour());
                        $this->mm->returnItem($settlement, $recruit->getEquipment());
                        $this->mm->returnItem($settlement, $recruit->getMount());
                        $settlement->setPopulation($settlement->getPopulation() + 1);
                        $em->remove($recruit);
                }
                $em->flush();
                return new RedirectResponse($this->generateUrl('maf_unit_soldiers', ["unit" => $unit->getId()]) . '#recruits');
        }

        #[Route('/units/{unit}/assign', name: 'maf_unit_assign', requirements: ['unit' => '\d+'])]
        public function unitAssignAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitAssignTest', $unit);
                # Distpatcher->getTest('test', default, default, default, UnitId)
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $options = $this->ud->getActionableCharacters(true);
                $options[] = $character;

                $form = $this->createForm(CharacterSelectType::class, null, [
                        'characters' => $options,
                        'empty' => 'unit.assign.empty',
                        'label' => 'unit.assign.select',
                        'submit' => 'unit.assign.submit',
                        'domain' => 'actions',
                        'required' => true,
                ]);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        $em = $this->em;
                        if ($unit->getCharacter()) {
                                $this->hist->closeLog($unit, $unit->getCharacter());
                        }
                        if ($here = $unit->getSettlement()) {
                                if ($here->getOwner() && $here->getOwner() !== $data['target']) {
                                        $this->hist->closeLog($unit, $here->getOwner());
                                }
                                if ($here->getSteward() && $here->getSteward() !== $data['target']) {
                                        $this->hist->closeLog($unit, $here->getSteward());
                                }
                        }
                        if ($unit->getMarshal() && $unit->getMarshal() !== $data['target']) {
                                $this->hist->closeLog($unit, $unit->getMarshal());
                        }
                        $unit->setCharacter($data['target']);
                        $this->hist->openLog($unit, $data['target']);
                        $this->hist->logEvent(
                                $data['target'],
                                'event.unit.assigned',
                                array('%link-unit%' => $unit->getId(), '%link-character%' => $character->getId()),
                                History::MEDIUM,
                                false,
                                30
                        );
                        $em->flush();
                        $this->addFlash('notice', $this->trans->trans('unit.assign.success', array(), 'actions'));
                        return $this->redirectToRoute('maf_units');
                }

                return $this->render('Unit/assign.html.twig', [
                        'unit' => $unit,
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/appoint', name: 'maf_unit_appoint', requirements: ['unit' => '\d+'])]
        public function unitAppointAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitAppointTest', $unit);
                # Distpatcher->getTest('test', default, default, default, UnitId)
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $options = $this->ud->getActionableCharacters(true); # Returns an array.
                $options[] = $character;
                # Check if the unit has a settlement, and if so, set $realm to the realm of that settlement, if any, and check if realm exists.
                if ($unit->getSettlement() && $realm = $unit->getSettlement()->getRealm()) {
                        # Get all members of the ultimate realm of the settlement.
                        foreach ($realm->findUltimate()->findActiveMembers() as $char) {
                                # Check if we already have them, if not: add.
                                if (!in_array($char, $options)) {
                                        $options[] = $char;
                                }
                        }
                }

                $form = $this->createForm(CharacterSelectType::class, null, [
                        'characters' => $options,
                        'empty' => 'unit.appoint.empty',
                        'label' => 'unit.appoint.select',
                        'submit' => 'unit.appoint.submit',
                        'domain' => 'actions',
                        'required' => false,
                ]);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        $em = $this->em;
                        if ($unit->getMarshal() && $unit->getMarshal() !== $data['target']) {
                                $this->hist->closeLog($unit, $unit->getMarshal());
                        }
                        $unit->setMarshal($data['target']);
                        $this->hist->openLog($unit, $data['target']);
                        $this->hist->logEvent(
                                $data['target'],
                                'event.unit.appointed',
                                array('%unit%' => $unit->getSettings()->getName(), '%link-character%' => $character->getId()),
                                History::MEDIUM,
                                false,
                                30
                        );
                        $em->flush();
                        $this->addFlash('notice', $this->trans->trans('unit.appoint.success', array(), 'actions'));
                        return $this->redirectToRoute('maf_units');
                }

                return $this->render('Unit/appoint.html.twig', [
                        'unit' => $unit,
                        'form' => $form->createView()
                ]);
        }

        /**
         * @Route("/units/{unit}/revoke", name="maf_unit_revoke", requirements={"unit"="\d+"})
         */
        #[Route('/units/{unit}/revoke', name: 'maf_unit_revoke', requirements: ['unit' => '\d+'])]

        public function unitRevokeAction(Unit $unit): RedirectResponse
        {
                $character = $this->gateway('unitAppointTest', $unit);
                # Distpatcher->getTest('test', default, default, default, UnitId)
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                $em = $this->em;
                $unit->setMarshal();
                $em->flush();
                $this->addFlash('notice', $this->trans->trans('unit.revoke.success', array(), 'actions'));
                return $this->redirectToRoute('maf_units');
        }

        /**
         * @Route("/units/{unit}/rebase", name="maf_unit_rebase", requirements={"unit"="\d+"})
         */

        public function unitRebaseAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitRebaseTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $options = $character->findControlledSettlements();
                $inside = $character->getInsideSettlement();
                if ($inside && $this->pm->checkSettlementPermission($inside, $character, 'units') && !$options->contains($inside)) {
                        $options->add($inside);
                }

                $form = $this->createForm(UnitRebaseType::class, null, ['settlements' => $options]);
                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        $success = $this->mm->rebaseUnit($data, $options, $unit);
                        if ($success) {
                                $this->em->flush();
                                $this->addFlash('notice', $this->trans->trans('unit.rebase.success', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.rebase.failed', array(), 'actions'));
                        }
                }

                return $this->render('Unit/rebase.html.twig', [
                        'unit' => $unit,
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/disband', name: 'maf_unit_disband', requirements: ['unit' => '\d+'])]

        public function unitDisbandAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitDisbandTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                $form = $this->createForm(AreYouSureType::class);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $success = $this->mm->disbandUnit($unit);
                        if ($success) {
                                $this->addFlash('notice', $this->trans->trans('unit.disband.success', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.disband.failed', array(), 'actions'));
                        }
                }

                return $this->render('Unit/disband.html.twig', [
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/return', name: 'maf_unit_return', requirements: ['unit' => '\d+'])]
        public function unitReturnAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitReturnTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                $form = $this->createForm(AreYouSureType::class);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $success = $this->mm->returnUnitHome($unit, 'returned', $character);
                        $this->em->flush();
                        if ($success) {
                                $this->addFlash('notice', $this->trans->trans('unit.return.success', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.return.failed', array(), 'actions'));
                        }
                }

                return $this->render('Unit/return.html.twig', [
                        'unit' => $unit,
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/{unit}/recall', name: 'maf_unit_recall', requirements: ['unit' => '\d+'])]
        public function unitRecallAction(Request $request, Unit $unit): RedirectResponse|Response
        {
                $character = $this->gateway('unitRecallTest', $unit);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }

                $form = $this->createForm(AreYouSureType::class);

                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                        $leader = $unit->getCharacter();
                        $success = $this->mm->returnUnitHome($unit, 'recalled', $leader);
                        if ($success) {
                                $this->hist->logEvent(
                                        $leader,
                                        'event.unit.recalled',
                                        array('%unit%' => $unit->getSettings()->getName(), '%link-character%' => $character->getId()),
                                        History::MEDIUM,
                                        false,
                                        30
                                );
                                $this->em->flush();
                                $this->addFlash('notice', $this->trans->trans('unit.recall.success', array(), 'actions'));
                                return $this->redirectToRoute('maf_units');
                        } else {
                                $this->addFlash('error', $this->trans->trans('unit.recall.failed', array(), 'actions'));
                        }
                }

                return $this->render('Unit/recall.html.twig', [
                        'form' => $form->createView()
                ]);
        }

        #[Route('/units/recruit', name: 'maf_recruit')]
        public function unitRecruitAction(Economy $economy, Generator $generator, Request $request): RedirectResponse|Response
        {
                list($character, $settlement) = $this->gateway('unitRecruitTest', null, true);
                if (!$character instanceof Character) {
                        return $this->redirectToRoute($character);
                }
                $em = $this->em;

                $query = $em->createQuery('SELECT COUNT(s) as number, SUM(s.training_required) AS training FROM BM2SiteBundle:Soldier s JOIN s.unit u WHERE u.settlement = :here AND s.training_required > 0');
                #$query = $em->createQuery('SELECT COUNT(s) as number, SUM(s.training_required) AS training FROM BM2SiteBundle:Soldier s WHERE s.base = :here AND s.training_required > 0');
                $query->setParameter('here', $settlement);
                $allocated = $query->getSingleResult();
                $allUnits = $settlement->getUnits();
                $units = [];
                foreach ($allUnits as $unit) {
                        if ($unit->getSoldiers()->count() < 200 && ($unit->getSettings()->getReinforcements() || !$unit->getCharacter()) && !$unit->getDisbanded()) {
                                $units[] = $unit;
                        }
                }
                if (count($units) < 1) {
                        $units[] = $this->mm->newUnit(null, $settlement, null); #Ensure we always have atleast 1!
                        return $this->redirectToRoute('maf_recruit'); # Reload page to avoid the "property assessore requires a graph or array of objects to operate on" error.
                }

                $soldierscount = 0;
                foreach ($settlement->getUnits() as $unit) {
                        $soldierscount += $unit->getSoldiers()->count();
                }
                $available = $this->mm->findAvailableEquipment($settlement, true);
                $form = $this->createForm(SoldiersRecruitType::class, null, ['available_equipment' => $available, 'units' => $units]);
                $form->handleRequest($request);

                $renderArray = [
                        'soldierscount' => $soldierscount,
                        'settlement' => $settlement,
                        'allocated' => $allocated,
                        'training' => $this->mm->findAvailableEquipment($settlement, true),
                        'form' => $form->createView(),
                ];

                if ($form->isSubmitted() && $form->isValid()) {
                        $data = $form->getData();
                        if ($data['unit']->getSettlement() != $settlement) {
                                $form->addError(new FormError("recruit.troops.unitnothere"));
                                return $this->render('Unit/recruit.html.twig', $renderArray);
                        }

                        if ($data['number'] > $settlement->getPopulation()) {
                                $form->addError(new FormError("recruit.troops.toomany"));
                                return $this->render('Unit/recruit.html.twig', $renderArray);
                        }
                        if ($data['number'] > $settlement->getRecruitLimit()) {
                                $form->addError(new FormError($this->trans->trans("recruit.troops.toomany2"), null, array('%max%' => $settlement->getRecruitLimit(true))));
                                return $this->render('Unit/recruit.html.twig', $renderArray);
                        }
                        if ($data['number'] > $data['unit']->getAvailable()) {
                                $this->addFlash('notice', $this->trans->trans('recruit.troops.unitmax', array('%only%' => $data['unit']->getAvailable() - $data['number'], '%planned%' => $data['number']), 'actions'));
                        }

                        for ($i = 0; $i < $data['number']; $i++) {
                                if (!$data['weapon']) {
                                        $form->addError(new FormError("recruit.troops.noweapon"));
                                        return $this->render('Unit/recruit.html.twig', $renderArray);
                                }
                        }
                        $count = 0;
                        $corruption = $economy->calculateCorruption($settlement);
                        for ($i = 0; $i < $data['number']; $i++) {
                                if ($soldier = $generator->randomSoldier($data['weapon'], $data['armour'], $data['equipment'], $data['mount'], $settlement, $corruption, $data['unit'])) {
                                        $this->hist->addToSoldierLog(
                                                $soldier,
                                                'recruited',
                                                array(
                                                        '%link-character%' => $character->getId(), '%link-settlement%' => $settlement->getId(),
                                                        '%link-item-1%' => $data['weapon'] ? $data['weapon']->getId() : 0,
                                                        '%link-item-2%' => $data['armour'] ? $data['armour']->getId() : 0,
                                                        '%link-item-3%' => $data['equipment'] ? $data['equipment']->getId() : 0,
                                                        '%link-item-4%' => $data['mount'] ? $data['mount']->getId() : 0
                                                )
                                        );
                                        $count++;
                                }
                        }
                        if ($count < $data['number']) {
                                $this->addFlash('notice', $this->trans->trans('recruit.troops.supply', array('%only%' => $count, '%planned%' => $data['number']), 'actions'));
                        }

                        $settlement->setPopulation($settlement->getPopulation() - $count);
                        $settlement->setRecruited($settlement->getRecruited() + $count);
                        $em->flush();
                        return $this->redirectToRoute('maf_unit_soldiers', array('unit' => $data['unit']->getId()));
                }

                return $this->render('Unit/recruit.html.twig', $renderArray);
        }
}
