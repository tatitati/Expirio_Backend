<?php
namespace App\Application\Service;

use App\Domain\ReadSlot\ReadSlot;
use App\Domain\ReadSlot\WrongPasswordUsed;
use App\Domain\WriteSlot\WriteSlot;
use App\Infrastructure\SlotsManagerRedis;
use Exception;
use ReflectionClass;

class CommandHandler
{
	/** @var SlotsManagerRedis */
	private $redisManager;

	public function __construct(SlotsManagerRedis $redisManager)
	{
		$this->redisManager = $redisManager;
	}

	public function handle($command)
	{
		$handleMethod = 'handle' . (new ReflectionClass($command))->getShortName();
		return $this->$handleMethod($command);
	}

	private function handleCreatePairSlotsCommand(CreatePairSlotsCommand $command): PairSlot
	{
		$read = new ReadSlot($command->getReadUid(), $command->getReadPassword());
		$write = new WriteSlot($command->getWriteUid(), $command->getReadUid());

		$this->redisManager->persistSlot($write);
		$this->redisManager->persistSlot($read);

		$this->redisManager->setExpiration($command->getReadUid(), $command->getExpirationSeconds());
		$this->redisManager->setExpiration($command->getWriteUid(), $command->getExpirationSeconds());

		return new PairSlot($read, $write);
	}

	private function handleWriteSecretCommand(WriteSecretCommand $command)
	{
		$writeSlot = $this->redisManager->fetchSlot($command->getWriteUid());
		
		if ($writeSlot && $writeSlot instanceof WriteSlot) {
			$readSlot = $this->redisManager->fetchSlot($writeSlot->getReadUi());
			
			if ($readSlot && $readSlot instanceof ReadSlot) {
				$readSlot->setSecret($command->getSecret());
				$this->redisManager->persistSlot($readSlot);
			    $this->redisManager->deleteSlot($writeSlot);
			}
		} else {
			throw new Exception('Cannot write secret: The write-slot doesnt exist or is not a write-slot');
		}
	}

	private function handleReadSecretQuery(ReadSecretQuery $command)
	{
		$readSlot = $this->redisManager->fetchSlot($command->getReadUid());

		if ($readSlot && $readSlot instanceof ReadSlot) {

			$secret = $readSlot->revealSecret($command->getPassword());

			if ($secret) {
				$this->redisManager->deleteSlot($readSlot);
				return $secret;
			}

			if($readSlot->getAmountOfAttempts() >= 3) {
				$this->redisManager->deleteSlot($readSlot);
				throw new Exception('Reading slot: Max failed attempts readched');
			} elseif ($readSlot->getAmountOfAttempts() >= 0) {
				$this->redisManager->persistSlot($readSlot);
				throw new Exception('Reading slot: Invalid password');
			}

		}

		throw new Exception('The read-slot doesnt exist or the password to read is invalid');
	}
}
