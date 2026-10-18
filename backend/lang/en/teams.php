<?php

return [
    'higher_team' => 'Players of :team cannot also play in :lower (a higher team). Please use cross-nomination for trainings.',
    'league_in_use' => 'This league still has sub-leagues or teams.',
    'league_parent_invalid' => 'A league cannot be placed below itself.',
    'group_invalid' => 'This training group does not belong to the event’s team.',
    'group_member_invalid' => 'Only people from the team can join a training group.',
    'cross' => [
        'youth' => 'Youth teams are excluded from cross-nomination.',
        'only_upwards' => 'Cross-nomination only works upwards: :home is not below :host (set the leagues of the teams).',
        'gender' => 'Men’s teams can only invite men, women’s teams only women.',
        'not_in_home' => 'This person does not play in the given home team.',
        'already_member' => 'This person already belongs to the team.',
    ],
];
