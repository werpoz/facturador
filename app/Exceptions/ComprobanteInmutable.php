<?php

namespace App\Exceptions;

use LogicException;

/** Se intentó alterar un comprobante que ya fue emitido. Lo emitido solo se corrige con otro comprobante. */
class ComprobanteInmutable extends LogicException {}
