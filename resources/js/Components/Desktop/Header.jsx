import { useState, useEffect } from 'react';
import { Box, Group, Text, ActionIcon, Menu, Avatar, Tooltip, useMantineTheme } from '@mantine/core';
import { usePage, router } from '@inertiajs/react';
import {
    IconBell,
    IconSettings,
    IconLogout,
    IconUser,
    IconWifi,
    IconCpu,
} from '@tabler/icons-react';

export function Header() {
    const theme = useMantineTheme();
    const [currentTime, setCurrentTime] = useState(new Date());
    const { auth } = usePage().props;
    const userName = auth?.user?.name;
    const userInitial = userName?.charAt(0).toUpperCase() || 'U';

    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentTime(new Date());
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    const formatTime = (date) => {
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    };

    const formatDate = (date) => {
        return date.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <Box
            style={{
                height: '48px',
                backgroundColor: theme.colors.dark[7],
                borderBottom: `1px solid ${theme.colors.dark[5]}`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '0 16px',
                position: 'fixed',
                top: 0,
                left: 0,
                right: 0,
                zIndex: 1000,
            }}
        >
            {/* Left Section - Logo */}
            <Group gap="sm">
                <Text size="xl" fw={700} c="blue">
                    NovaNAS
                </Text>
            </Group>

            {/* Right Section - System Tray */}
            <Group gap="md">
                {/* System Status Indicators */}
                <Group gap={8}>
                    <Tooltip label="Network: Connected">
                        <IconWifi size={16} color={theme.colors.green[5]} />
                    </Tooltip>
                    <Tooltip label="CPU: Normal">
                        <IconCpu size={16} color={theme.colors.blue[5]} />
                    </Tooltip>
                </Group>

                {/* Notifications */}
                <Menu shadow="md" width={200} position="bottom-end">
                    <Menu.Target>
                        <ActionIcon variant="subtle" color="gray" size="lg">
                            <IconBell size={18} color="white" />
                        </ActionIcon>
                    </Menu.Target>
                    <Menu.Dropdown>
                        <Menu.Label>Notifications</Menu.Label>
                        <Menu.Item>No new notifications</Menu.Item>
                    </Menu.Dropdown>
                </Menu>

                {/* Time Display */}
                <Box style={{ textAlign: 'right' }}>
                    <Text size="sm" c="white" fw={500}>
                        {formatTime(currentTime)}
                    </Text>
                    <Text size="xs" c="dimmed">
                        {formatDate(currentTime)}
                    </Text>
                </Box>

                {/* User Menu */}
                <Menu shadow="md" width={200} position="bottom-end">
                    <Menu.Target>
                        <ActionIcon variant="subtle" size="lg" radius="xl">
                            <Avatar size="sm" color="blue">
                                {userInitial}
                            </Avatar>
                        </ActionIcon>
                    </Menu.Target>
                    <Menu.Dropdown>
                        <Menu.Label>{userName}</Menu.Label>
                        <Menu.Item leftSection={<IconUser size={14} />}>
                            Profile
                        </Menu.Item>
                        <Menu.Item leftSection={<IconSettings size={14} />}>
                            Settings
                        </Menu.Item>
                        <Menu.Divider />
                        <Menu.Item
                            color="red"
                            leftSection={<IconLogout size={14} />}
                            onClick={() => router.post('/logout')}
                        >
                            Logout
                        </Menu.Item>
                    </Menu.Dropdown>
                </Menu>
            </Group>
        </Box>
    );
}
