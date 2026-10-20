<?php

namespace CorelixIo\Platform\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * Compat shim for <x-forms.select>.
 * Upstream Coolify removed App\View\Components\Forms\Select in v4.4.0.
 * Our Livewire views still use <x-forms.select>, so we bundle it here.
 */
class Select extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $label = null,
        public ?string $helper = null,
        public bool $required = false,
        public bool $disabled = false,
        public string $defaultClass = 'select w-full',
        public ?string $canGate = null,
        public mixed $canResource = null,
        public bool $autoDisable = true,
    ) {
        if ($this->canGate && $this->canResource && $this->autoDisable) {
            $hasPermission = Gate::allows($this->canGate, $this->canResource);
            if (! $hasPermission) {
                $this->disabled = true;
            }
        }
    }

    public function render(): View|Closure|string
    {
        $this->modelBinding = $this->id;

        if (is_null($this->id)) {
            $this->id = new_public_id();
            $this->modelBinding = 'null';
        }

        $this->htmlId = $this->id;

        if (! is_null($this->name)) {
            $this->htmlId = $this->name;
        }

        return view('corelix-platform::components.forms.select');
    }
}
