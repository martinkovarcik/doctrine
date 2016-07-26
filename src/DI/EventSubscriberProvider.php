<?php

namespace Esports\Doctrine\DI;

interface EventSubscriberProvider
{
	
	/**
	 * @return array
	 */
	public function getEventSubscribers();
}
