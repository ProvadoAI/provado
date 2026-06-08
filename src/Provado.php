<?php

namespace Mquevedob\Provado;

class Provado
{
    public function enabled(): bool
    {
        return (bool) config('provado.enabled', true);
    }
}
