#!/bin/bash
# Replay of a full TaskR issue-dev lifecycle against .vibe/hooks/issue-dev.php,
# with well-formed JSON payloads (no shell-escaping traps).
set -u
cd /Users/hadriendelphin/Documents/projects/perso/TaskR || exit 1
HOOK=".vibe/hooks/issue-dev.php"
ST=$(php -r 'echo sys_get_temp_dir()."/vibe-issue-dev-".substr(sha1("/Users/hadriendelphin/Documents/projects/perso/TaskR"),0,12).".json";')
WS=01ARZ3NDEKTSV4RRFFQ69G5FAU
IS=01ARZ3NDEKTSV4RRFFQ69G5FAV
rm -f "$ST"

say() { echo; echo "=== $1 ==="; }

say "1. status (no workflow)"
php $HOOK status

say "2. start"
php $HOOK start $WS $IS

say "3. pre_tool edit before plan -> DENY expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"edit","tool_input":{"file_path":"app/Models/User.php"}}
EOF

say "4. pre_tool bash on the CLI itself -> passthrough expected"
php $HOOK <<EOF
{"hook_event_name":"pre_tool","tool_name":"bash","tool_input":{"command":"php .vibe/hooks/issue-dev.php knowledge-reviewed"}}
EOF
echo "(exit $?)"

say "5. post_tool search_knowledge -> record expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"success","tool_input":{"code":"await tools.connector_taskr_34e2.search_knowledge({query: 'ulid conventions', workspace_id});"}}
EOF
echo "(exit $?)"

say "6. pre_tool plan comment without code exploration -> DENY expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"run_typescript","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:plan -->\nPlan here.'});"}}
EOF

say "7. post_tool bash grep -> code_explored expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"bash","tool_status":"success","tool_input":{"command":"grep -rn UserService app/"}}
EOF
echo "(exit $?)"

say "8. pre_tool plan comment -> passthrough expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"run_typescript","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:plan -->\nPlan here.'});"}}
EOF
echo "(exit $?)"

say "9. post_tool add_comment with plan marker -> record expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"success","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:plan -->\nPlan here.'});"}}
EOF
echo "(exit $?)"

say "10. failed call must NOT record (unmarked comment + status failure)"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"failure","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:plan -->'});"}}
EOF
echo "(exit $?)"

say "11. pre_tool edit after plan -> passthrough expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"edit","tool_input":{"file_path":"app/Models/User.php"}}
EOF
echo "(exit $?)"

say "12. pre_tool log_time before done -> DENY expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"run_typescript","tool_input":{"code":"await tools.connector_taskr_34e2.log_time({hours: 2});"}}
EOF

say "13. pre_tool second plan comment -> DENY expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"run_typescript","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:plan --> again'});"}}
EOF

say "14. pre_tool done comment -> passthrough expected"
php $HOOK <<'EOF'
{"hook_event_name":"pre_tool","tool_name":"run_typescript","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:done -->\nDone report.'});"}}
EOF
echo "(exit $?)"

say "15. post_tool done comment -> record expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"success","tool_input":{"code":"await tools.connector_taskr_34e2.add_comment({body: '<!-- taskr:done -->\nDone report.'});"}}
EOF
echo "(exit $?)"

say "16. post_tool log_time -> record expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"success","tool_input":{"code":"await tools.connector_taskr_34e2.log_time({hours: 2.5, worked_at: '2026-10-17'});"}}
EOF
echo "(exit $?)"

say "17. post_agent -> DENY expected (knowledge review missing)"
php $HOOK <<'EOF'
{"hook_event_name":"post_agent"}
EOF

say "18. knowledge-reviewed CLI"
php $HOOK knowledge-reviewed

say "19. post_agent -> passthrough + close expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_agent"}
EOF
echo "(exit $?)"

say "20. status after close"
php $HOOK status

say "21. nudge: connector used with no workflow -> context expected"
php $HOOK <<'EOF'
{"hook_event_name":"post_tool","tool_name":"run_typescript","tool_status":"success","tool_input":{"code":"await tools.connector_taskr_34e2.list_issues({workspace_id: 'w'});"}}
EOF

say "22. final state"
cat "$ST"
echo
echo "=== cleanup ==="
php $HOOK cancel
rm -f "$ST"
