#!/bin/sh
#
# 重置用户密码脚本
#
# 使用方法:
#   ./repassword.sh [账号标识符] [自定义密码]
#
# 参数说明:
#   [账号标识符]: 可选，可以是用户ID(纯数字)或邮箱地址。不提供时默认为第一个管理员用户
#   [自定义密码]: 可选，指定要设置的新密码。不提供时会自动生成随机密码
#
# 使用示例:
#   ./repassword.sh                     # 重置第一个管理员用户密码(随机生成)
#   ./repassword.sh 123                 # 重置ID=123的用户密码(随机生成)
#   ./repassword.sh user@example.com    # 重置邮箱为user@example.com的用户密码(随机生成)
#   ./repassword.sh 123 newpass         # 重置ID=123的用户密码为"newpass"
#   ./repassword.sh user@example.com newpass  # 重置邮箱为user@example.com的用户密码为"newpass"
#

account_identifier=$1
custom_password=$2

GreenBG="\033[42;37m"
RedBG="\033[41;37m"
Font="\033[0m"

# 生成随机密码
new_encrypt=$(date +%s%N | md5sum | awk '{print $1}' | cut -c 1-6)
if [ -z "$custom_password" ]; then
    new_password=$(date +%s%N | md5sum | awk '{print $1}' | cut -c 1-16)
else
    new_password=$custom_password
fi
md5_password=$(echo -n `echo -n $new_password | md5sum | awk '{print $1}'`$new_encrypt | md5sum | awk '{print $1}')

# 构建查询条件
if [ -z "$account_identifier" ]; then
    # 默认查询第一个管理员
    admin_query=$(echo "SELECT userid FROM ${MYSQL_PREFIX}users WHERE identity LIKE '%,admin,%' ORDER BY userid LIMIT 1;" | mysql -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE)
    identifier_value=$(echo "$admin_query" | sed -n '2p')

    if [ -z "$identifier_value" ]; then
        echo "${RedBG}错误：未找到管理员用户！${Font}"
        exit 1
    fi

    where_field="userid"
    identifier_type="管理员ID"
else
    # 检查是否为纯数字（ID）
    # 使用更兼容的 shell 语法检查是否为纯数字
    case "$account_identifier" in
        ''|*[!0-9]*)
            # 非纯数字，视为邮箱
            where_field="email"
            identifier_type="邮箱"
            identifier_value="$account_identifier"
            ;;
        *)
            # 纯数字，视为ID
            where_field="userid"
            identifier_type="ID"
            identifier_value="$account_identifier"
            ;;
    esac
fi

# 构建 WHERE 条件（为邮箱添加引号）
if [ "$where_field" = "email" ]; then
    where_condition="where $where_field='$identifier_value'"
else
    where_condition="where $where_field=$identifier_value"
fi

# 查询用户信息
content=$(echo "select userid,email from ${MYSQL_PREFIX}users $where_condition;" | mysql -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE)

# 提取用户ID和邮箱
user_id=$(echo "$content" | sed -n '2p' | awk '{print $1}')
account=$(echo "$content" | sed -n '2p' | awk '{print $2}')

if [ -z "$account" ]; then
    echo "${RedBG}错误：${identifier_type} '${identifier_value}' 的账号不存在！${Font}"
    exit 1
fi

# 更新密码
mysql -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE <<EOF
update ${MYSQL_PREFIX}users set encrypt='${new_encrypt}',password='${md5_password}' $where_condition;
EOF

# 只在 identifier_type="ID" 时才输出ID
if [ "$identifier_type" = "ID" ]; then
    echo "ID: ${GreenBG}${user_id}${Font}"
fi

# 输出邮箱和密码
echo "邮箱: ${GreenBG}${account}${Font}"
echo "密码: ${GreenBG}${new_password}${Font}"
