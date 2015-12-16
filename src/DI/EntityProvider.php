<?php

namespace Esports\Doctrine\DI;

interface EntityProvider
{

	/**
	 * @return array
	 */
	function getEntityMapping();
}
