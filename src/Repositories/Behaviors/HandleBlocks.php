<?php

namespace A17\Twill\Repositories\Behaviors;

use A17\Twill\Models\Behaviors\HasMedias;
use A17\Twill\Repositories\BlockRepository;

trait HandleBlocks
{
    public function hydrateHandleBlocks($object, $fields)
    {
        if ($this->shouldIgnoreFieldBeforeSave('blocks')) {
            return;
        }

        $blocksCollection = collect();
        $blocksFromFields = $this->getBlocks($object, $fields);
        $blockRepository = app(BlockRepository::class);

        $blocksFromFields->each(function ($block, $key) use ($blocksCollection, $blockRepository) {
            $newBlock = $blockRepository->createForPreview($block);
            $newBlock->id = $key + 1;

            $blocksCollection->push($newBlock);

            $block['blocks']->each(function ($childBlock) use ($newBlock, $blocksCollection, $blockRepository) {
                $childBlock['parent_id'] = $newBlock->id;
                $newChildBlock = $blockRepository->createForPreview($childBlock);
                $blocksCollection->push($newChildBlock);
            });

        });

        $object->setRelation('blocks', $blocksCollection);

        return $object;

    }

    public function afterSaveHandleBlocks($object, $fields)
    {
        if ($this->shouldIgnoreFieldBeforeSave('blocks')) {
            return;
        }

        $blockRepository = app(BlockRepository::class);

        $blockRepository->bulkDelete($object->blocks()->pluck('id')->toArray());

        $this->getBlocks($object, $fields)->each(function ($block) use ($object, $blockRepository) {

            $blockCreated = $blockRepository->create($block);

            $block['blocks']->each(function ($childBlock) use ($blockCreated, $blockRepository) {
                $childBlock['parent_id'] = $blockCreated->id;
                $blockRepository->create($childBlock);
            });
        });
    }

    private function getBlocks($object, $fields)
    {
        $blocks = collect();
        if (isset($fields['blocks']) && is_array($fields['blocks'])) {

            foreach ($fields['blocks'] as $index => $block) {
                $block = $this->buildBlock($block, $object);
                $block['position'] = $index + 1;

                $childBlocksList = collect();

                foreach ($block['blocks'] as $childKey => $childBlocks) {
                    foreach ($childBlocks as $index => $childBlock) {
                        $childBlock = $this->buildBlock($childBlock, $object, true);

                        $childBlock['child_key'] = $childKey;
                        $childBlock['position'] = $index + 1;

                        $childBlocksList->push($childBlock);
                    }
                }

                $block['blocks'] = $childBlocksList;

                $blocks->push($block);
            }
        }

        return $blocks;
    }

    private function buildBlock($block, $object, $repeater = false)
    {
        $block['blockable_id'] = $object->id;
        $block['blockable_type'] = $object->getMorphClass();

        return app(BlockRepository::class)->buildFromCmsArray($block, $repeater);
    }

    public function getFormFieldsHandleBlocks($object, $fields)
    {
        $fields['blocks'] = null;

        if ($object->has('blocks')) {

            $blocksConfig = config('twill.block_editor');

            foreach ($object->blocks as $block) {
                $isInRepeater = isset($block->parent_id);
                $configKey = $isInRepeater ? 'repeaters' : 'blocks';
                $blockTypeConfig = $blocksConfig[$configKey][$block->type] ?? null;

                if (is_null($blockTypeConfig)) {
                    continue;
                }

                $blockItem = [
                    'id' => $block->id,
                    'type' => $blockTypeConfig['component'],
                    'title' => $blockTypeConfig['title'],
                    'attributes' => $blockTypeConfig['attributes'] ?? [],
                ];

                if ($isInRepeater) {
                    $fields['blocksRepeaters']["blocks-{$block->parent_id}_{$block->child_key}"][] = $blockItem + [
                        'max' => $blockTypeConfig['max'],
                        'trigger' => $blockTypeConfig['trigger'],
                    ];
                } else {
                    $fields['blocks'][] = $blockItem + [
                        'icon' => $blockTypeConfig['icon'],
                    ];
                }

                $fields['blocksFields'][] = collect($block['content'])->filter(function ($value, $key) {
                    return $key !== "browsers";
                })->map(function ($value, $key) use ($block) {
                    return [
                        'name' => "blocks[$block->id][$key]",
                        'value' => $value,
                    ];
                })->filter()->values()->toArray();

                $blockFormFields = app(BlockRepository::class)->getFormFields($block);

                $medias = $blockFormFields['medias'];

                if ($medias) {
                    $fields['blocksMedias'][] = collect($medias)->mapWithKeys(function ($value, $key) use ($block) {
                        return [
                            "blocks[$block->id][$key]" => $value,
                        ];
                    })->filter()->toArray();
                }

                $files = $blockFormFields['files'];

                if ($files) {
                    collect($files)->each(function ($rolesWithFiles, $locale) use (&$fields, $block) {
                        $fields['blocksFiles'][] = collect($rolesWithFiles)->mapWithKeys(function ($files, $role) use ($locale, $block) {
                            return [
                                "blocks[$block->id][$role][$locale]" => $files,
                            ];
                        })->toArray();
                    });
                }

                if (isset($block['content']['browsers'])) {
                    $fields['blocksBrowsers'][] = $this->getBlockBrowsers($block);
                }
            }

            if ($fields['blocksFields'] ?? false) {
                $fields['blocksFields'] = call_user_func_array('array_merge', $fields['blocksFields'] ?? []);
            }

            if ($fields['blocksMedias'] ?? false) {
                $fields['blocksMedias'] = call_user_func_array('array_merge', $fields['blocksMedias'] ?? []);
            }

            if ($fields['blocksFiles'] ?? false) {
                $fields['blocksFiles'] = call_user_func_array('array_merge', $fields['blocksFiles'] ?? []);
            }

            if ($fields['blocksBrowsers'] ?? false) {
                $fields['blocksBrowsers'] = call_user_func_array('array_merge', $fields['blocksBrowsers'] ?? []);
            }
        }

        return $fields;
    }

    protected function getBlockBrowsers($block)
    {
        return collect($block['content']['browsers'])->mapWithKeys(function ($ids, $relation) use ($block) {
            $relationRepository = $this->getModelRepository($relation);
            $relatedItems = $relationRepository->get([], ['id' => $ids], [], -1);
            $sortedRelatedItems = array_flip($ids);

            foreach ($relatedItems as $item) {
                $sortedRelatedItems[$item->id] = $item;
            }

            $items = collect(array_values($sortedRelatedItems))->filter(function ($value) {
                return is_object($value);
            })->map(function ($relatedElement) use ($relation) {
                return [
                    'id' => $relatedElement->id,
                    'name' => $relatedElement->titleInBrowser ?? $relatedElement->title,
                    'edit' => moduleRoute($relation, config('twill.block_editor.browser_route_prefixes.' . $relation), 'edit', $relatedElement->id),
                ] + (classHasTrait($relatedElement, HasMedias::class) ? [
                    'thumbnail' => $relatedElement->defaultCmsImage(['w' => 100, 'h' => 100]),
                ] : []);
            })->toArray();

            return [
                "blocks[$block->id][$relation]" => $items,
            ];
        })->filter()->toArray();
    }
}
