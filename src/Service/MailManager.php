<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\MailEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

class MailManager {

	protected $em;
	protected $appstate;
	protected $trans;
	protected $mail_from;
	protected $mail_reply_to;
	protected $optOut;

	public function __construct(EntityManagerInterface $em, TranslatorInterface $translator, MailerInterface $mailer, AppState $appstate) {
		$this->em = $em;
		$this->appstate = $appstate;
		$this->trans = $translator;
		$this->mailer = $mailer;
		$this->mail_from = new Address($_ENV['FROM_EMAIL'], $_ENV['FROM_NAME']);
		$this->mail_reply_to = $_ENV['REPLY_EMAIL'];
		$this->optOut = $_ENV['MAIL_OPT_OUT_URL'];
	}

	public function spoolEvent(Event $event, User $user, $text) {
		$entry = new MailEntry();
		$this->em->persist($entry);
		$entry->setEvent($event);
		$entry->setUser($user);
		$entry->setType('event');
		$entry->setSendTime($this->calculateWhen($user->getEmailDelay()));
		$entry->setTs(new \DateTime('now'));
		$entry->setContent($text);
	}

	public function calculateWhen($delay) {
		switch ($delay) {
			case 'now':
				return new \DateTime('now');
			case 'hourly':
				$date = new \DateTime("+1 hour");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case '6h':
				$date = new \DateTime("now");
				$next = 6 - (intval($date->format("H")) % 6);
				$date->modify("+".$next." hours");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case '12h':
				$date = new \DateTime("now");
				$next = 12 - (intval($date->format("H")) % 12);
				$date->modify("+".$next." hours");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'daily':
				$date = new \DateTime("midnight + 1 day");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'sundays':
				$date = new \DateTime("next sunday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'mondays':
				$date = new \DateTime("next monday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'tuesdays':
				$date = new \DateTime("next tuesday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'wednesdays':
				$date = new \DateTime("next wednesday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'thursdays':
				$date = new \DateTime("next thursday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'fridays':
				$date = new \DateTime("next friday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			case 'saturdays':
				$date = new \DateTime("next saturday");
				return new \DateTime($date->format("Y-m-d H:00:00"));
			default:
				return new \DateTime("now");
		}
	}

	public function sendEventEmails() {
		$now = new \DateTime("now");
		$em = $this->em;
		$query = $em->createQuery("SELECT u FROM BM2SiteBundle:User u JOIN u.mail_entries m WHERE m.type = :type");
		$query->setParameters(['type'=>'event']);
		$users = $query->getResult();
		$twoMonths = new \DateTime("-2 months");

		foreach ($users as $user) {
			$remove = [];
			if ($user->getLastLogin() > $twoMonths)
			$bypass = false;
			$header = $this->trans->trans('mail.event.intro', array('%name%'=>$user->getUsername()), "communication")."<br><br>\n\n";
			$text = '';
			foreach ($user->getMailEntries() as $each) {
				if ($each->getSendTime() > $now) {
					$bypass = true;
					break; #Not time to send, these return oldest first, so we can skip this user.
				}
				$text .= $each->getContent()."<br>\n";
				$remove[] = $each;
			}
			if ($bypass) {
				break; #Not time to send anything, skip this user.
			}
			$text .= "<br>\n";
			$token = $this->appstate->findEmailOptOutToken($user);
			$link = $this->optOut.'/'.$user->getId().'/'.$token;
			$footer = $this->trans->trans('mail.event.footer', ['%link%'=>$link], "communication");

			$intro = "Hello ".$user->getUsername().",<br><br>\n\n";
			$msg = $intro.$header.$text.$footer;

			$sent = $this->sendEmail($user->getEmail(), $this->trans->trans('mail.event.subject', array(), "communication"), $msg);

			foreach ($remove as $each) {
				$em->remove($each);
			}
			$em->flush();
		}
	}

	public function sendEmail($to, $subject, $text) {
		$message = new Email;
		$message->setSubject($subject);
		$message->setFrom($this->mail_from);
		$message->setReplyTo($this->mail_reply_to);
		$message->setTo($to);
		$message->setBody(strip_tags($text));
		$message->addPart($text, 'text/html');
		$sent = $this->mailer->send($message);
		return $sent;
	}

}
