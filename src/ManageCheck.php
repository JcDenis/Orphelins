<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Helper\Html\Form\Checkbox;
use Dotclear\Helper\Html\Form\Date;
use Dotclear\Helper\Html\Form\Fieldset;
use Dotclear\Helper\Html\Form\Form;
use Dotclear\Helper\Html\Form\Label;
use Dotclear\Helper\Html\Form\Legend;
use Dotclear\Helper\Html\Form\Link;
use Dotclear\Helper\Html\Form\Note;
use Dotclear\Helper\Html\Form\Number;
use Dotclear\Helper\Html\Form\Para;
use Dotclear\Helper\Html\Form\Submit;
use Dotclear\Helper\Html\Form\Textarea;
use Dotclear\Helper\Html\Html;
use Dotclear\Helper\Process\TraitProcess;
use Exception;

/**
 * @brief       Orphelins check page.
 * @ingroup     Orphelins
 *
 * Start a check of the blog and follow it. This page is called by Manage.
 *
 * A check is done by steps (see Checker): the page is loaded again for each step,
 * by itself with JavaScript, else with a button. The page shows one of three forms:
 * no check (options and start), check going on (request "run", set by the redirection after each step),
 * or check left before its end (resume or give up).
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class ManageCheck
{
    use TraitProcess;

    private static ManageVars $vars;

    public static function init(): bool
    {
        if (self::status(My::checkContext(My::MANAGE))) {
            self::$vars = Manage::vars();
        }

        return self::status();
    }

    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        $params = [... self::$vars->params(), 'check' => 1];
        // The page of a check which goes on by itself, see render()
        $run = [... $params, 'run' => 1];

        try {
            if (isset($_POST['stop'])) {
                Checker::cancel();

                App::backend()->notices()->addWarningNotice(__('The check has been stopped. What was checked so far is kept.'));
                My::redirect(self::$vars->params());
            }

            if (isset($_POST['step'])) {
                if (!Checker::isRunning()) {
                    // Stopped meanwhile, or an old page of a check shown again by the browser: nothing to continue
                    My::redirect($params);
                }
                if (Checker::step()) {
                    App::backend()->notices()->addSuccessNotice(__('The check is finished.'));
                    My::redirect();
                }
                My::redirect($run);
            }

            if (isset($_POST['save']) || isset($_POST['start'])) {
                Checker::saveOptions(
                    ManageVars::post('ignored_domains'),
                    is_numeric(ManageVars::post('recheck_days')) ? (int) ManageVars::post('recheck_days') : Checker::RECHECK_DAYS,
                    ManageVars::post('since_date')
                );

                if (isset($_POST['start'])) {
                    if (!Checker::isRunning()) {
                        Checker::start(!empty($_POST['full']));
                    }
                    My::redirect($run);
                }

                App::backend()->notices()->addSuccessNotice(__('Options saved.'));
                My::redirect($params);
            }
        } catch (Exception $exception) {
            App::error()->add($exception->getMessage());
        }

        return true;
    }

    public static function render(): void
    {
        if (!self::status()) {
            return;
        }

        $back = My::manageUrl(self::$vars->params());

        App::backend()->page()->openModule(My::name(), Manage::head());

        echo
        App::backend()->page()->breadcrumb([
            Html::escapeHTML(App::blog()->name()) => '',
            My::name()                            => $back,
            __('New check')                       => '',
        ]) .
        App::backend()->notices()->getNotices();

        if (!Checker::isRunning()) {
            echo self::startForm()->render();
        } elseif (ManageVars::request('run') !== '') {
            echo self::stepForm()->render();
        } else {
            echo self::resumeForm()->render();
        }

        echo (new Para())
            ->items([
                (new Link())
                    ->class('back')
                    ->href($back)
                    ->text(__('Back to the list')),
            ])
            ->render();

        App::backend()->page()->helpBlock(My::id() . '-check');

        App::backend()->page()->closeModule();
    }

    /**
     * Get the form of a check going on: the page has just started it or done one of its steps.
     *
     * With JavaScript the next step is sent by itself and its button is hidden.
     */
    private static function stepForm(): Form
    {
        return (new Form('check-step-form'))
            ->method('post')
            ->action(My::manageUrl([... self::$vars->params(), 'check' => 1]))
            ->fields([
                (new Note())
                    ->class('info')
                    ->text(__('The check is going on. Keep this page open until the end: it goes on by itself.')),
                (new Note())
                    ->class('step-msg')
                    ->text(Html::escapeHTML(Checker::message())),
                (new Para())
                    ->class('form-buttons')
                    ->items([
                        (new Submit('step', __('Next step'))),
                        (new Submit('stop', __('Stop the check')))
                            ->class('delete'),
                        ... My::hiddenFields(),
                    ]),
            ]);
    }

    /**
     * Get the form of a check left before its end: nothing goes on until the user asks for it.
     */
    private static function resumeForm(): Form
    {
        return (new Form('check-resume-form'))
            ->method('post')
            ->action(My::manageUrl([... self::$vars->params(), 'check' => 1]))
            ->fields([
                (new Note())
                    ->class('warning')
                    ->text(__('A check was left before its end and is waiting. Resume it, or give it up to start a new one: what was checked so far is kept.')),
                (new Note())
                    ->text(Html::escapeHTML(sprintf(__('It stopped at: %s'), Checker::message()))),
                (new Para())
                    ->class('form-buttons')
                    ->items([
                        (new Submit('step', __('Resume the check'))),
                        (new Submit('stop', __('Give up the check')))
                            ->class('delete'),
                        ... My::hiddenFields(),
                    ]),
            ]);
    }

    /**
     * Get the form to start a check.
     */
    private static function startForm(): Form
    {
        // After an error, the form keeps what has been typed
        $posted = isset($_POST['save']) || isset($_POST['start']);
        $last   = Checker::lastCheck();

        return (new Form('check-start-form'))
            ->method('post')
            ->action(My::manageUrl([... self::$vars->params(), 'check' => 1]))
            ->fields([
                (new Note())
                    ->text($last > 0 ? sprintf(__('Last check: %s.'), Html::escapeHTML(Manage::date(date('Y-m-d H:i:s', $last)))) : __('This blog has not been checked yet.')),
                (new Note())
                    ->text(__('A check reads the entries of the blog, asks the web sites if their links still exist, looks for the files of the entries in the media library, then looks for the media no entry uses. It may take several minutes.')),
                (new Fieldset())
                    ->legend(new Legend(__('Options')))
                    ->fields([
                        (new Para())
                            ->items([
                                (new Date('since_date', Html::escapeHTML($posted ? Checker::cleanDate(ManageVars::post('since_date')) : Checker::sinceDate())))
                                    ->label((new Label(__('Only check the entries published since:'), Label::OL_TF))),
                            ]),
                        (new Note())
                            ->class('form-note')
                            ->text(__('Without date, all the entries are checked. With a date, older entries leave the list of links and files.')),
                        (new Para())
                            ->items([
                                (new Textarea('ignored_domains', Html::escapeHTML($posted ? ManageVars::post('ignored_domains') : implode("\n", Checker::ignoredDomains()))))
                                    ->cols(40)
                                    ->rows(5)
                                    ->label((new Label(__('Domains not to check:'), Label::OL_TF))),
                            ]),
                        (new Note())
                            ->class('form-note')
                            ->text(__('One domain per line (example.com). Links to these domains and to their subdomains are not checked.')),
                        (new Para())
                            ->items([
                                (new Number('recheck_days', 0, Checker::RECHECK_MAX, $posted && is_numeric(ManageVars::post('recheck_days')) ? (int) ManageVars::post('recheck_days') : Checker::recheckDays()))
                                    ->label((new Label(__('Number of days a link without problem is kept before being checked again:'), Label::OL_TF))),
                            ]),
                        (new Para())
                            ->items([
                                (new Checkbox('full', $posted && !empty($_POST['full'])))
                                    ->value(1)
                                    ->label(new Label(__('Check all the links again this time, even those checked a few days ago'), Label::IL_FT)),
                            ]),
                    ]),
                (new Para())
                    ->class('form-buttons')
                    ->items([
                        (new Submit('start', __('Start the check'))),
                        (new Submit('save', __('Save options only')))
                            ->class('reset'),
                        ... My::hiddenFields(),
                    ]),
            ]);
    }
}
