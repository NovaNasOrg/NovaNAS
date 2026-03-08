import { useEffect, useState } from 'react';
import { Box, Text, Badge, Group, LoadingOverlay, ThemeIcon, Table } from '@mantine/core';
import { IconRefresh, IconDisc, IconUsb, IconLock } from '@tabler/icons-react';
import { useMantineTheme } from '@mantine/core';

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function DiskCard({ disk }) {
    const theme = useMantineTheme();
    const isHDD = disk.rotational;
    const isRemovable = disk.removable;
    const isReadonly = disk.readonly;

    return (
        <Box
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: '20px',
                backgroundColor: theme.colors.dark[6],
                borderRadius: '12px',
                padding: '16px 20px',
                border: `1px solid ${theme.colors.dark[4]}`,
                transition: 'transform 0.2s ease, box-shadow 0.2s ease',
                marginBottom: '12px',
            }}
        >
            {/* Icon */}
            <ThemeIcon
                size={56}
                radius="md"
                variant="light"
                color={isHDD ? 'orange' : 'blue'}
            >
                <IconDisc size={28} />
            </ThemeIcon>

            {/* Device Info */}
            <Box style={{ flex: '0 0 120px' }}>
                <Text size="xl" fw={700}>/dev/{disk.name}</Text>
                <Text size="xs" c="dimmed">{disk.model || disk.vendor || 'Unknown'}</Text>
            </Box>

            {/* Capacity */}
            <Box style={{ flex: '0 0 140px' }}>
                <Text size="lg" fw={600}>{formatBytes(disk.size)}</Text>
                <Text size="xs" c="dimmed">Capacity</Text>
            </Box>

            {/* Type */}
            <Box style={{ flex: '0 0 160px' }}>
                <Badge
                    size="lg"
                    color={isHDD ? 'orange' : 'blue'}
                    variant="light"
                >
                    {isHDD ? 'HDD' : 'SSD'}
                </Badge>
                <Text size="xs" c="dimmed" mt={4}>Type</Text>
            </Box>

            {/* Serial */}
            <Box style={{ flex: 1 }}>
                <Text size="sm" fw={500}>{disk.serial || '-'}</Text>
                <Text size="xs" c="dimmed">Serial Number</Text>
            </Box>

            {/* Status Badges */}
            <Group gap="xs">
                {isReadonly && (
                    <Badge size="sm" color="red" variant="light" leftSection={<IconLock size={12} />}>
                        Locked
                    </Badge>
                )}
                {isRemovable && (
                    <Badge size="sm" color="gray" variant="light" leftSection={<IconUsb size={12} />}>
                        Removable
                    </Badge>
                )}
            </Group>
        </Box>
    );
}

export function DisksTab() {
    const [disks, setDisks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const theme = useMantineTheme();

    const fetchDisks = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch('/api/storage/disks', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            setDisks(data.disks || []);
        } catch (err) {
            setError('Failed to load disks');
            console.error('Error fetching disks:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchDisks();
    }, []);

    // Calculate total storage
    const totalStorage = disks.reduce((sum, disk) => sum + disk.size, 0);
    const hddCount = disks.filter(d => d.rotational).length;
    const ssdCount = disks.filter(d => !d.rotational).length;

    return (
        <Box style={{ position: 'relative' }}>
            <LoadingOverlay visible={loading} zIndex={1000} overlayProps={{ radius: 'sm', blur: 2 }} />

            {/* Summary Stats */}
            <Box
                style={{
                    backgroundColor: theme.colors.dark[6],
                    borderRadius: '12px',
                    padding: '16px 20px',
                    marginBottom: '24px',
                    border: `1px solid ${theme.colors.dark[4]}`,
                }}
            >
                <Group justify="space-between" align="center">
                    <Group gap="xl">
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Total Storage</Text>
                            <Text size="xl" fw={700}>{formatBytes(totalStorage)}</Text>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>HDDs</Text>
                            <Badge size="lg" color="orange" variant="light">{hddCount}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>SSDs</Text>
                            <Badge size="lg" color="blue" variant="light">{ssdCount}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Total Disks</Text>
                            <Badge size="lg" color="gray" variant="light">{disks.length}</Badge>
                        </Box>
                    </Group>
                    <Box
                        onClick={fetchDisks}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '6px',
                            padding: '8px 16px',
                            borderRadius: '8px',
                            cursor: 'pointer',
                            backgroundColor: theme.colors.blue[6],
                            color: 'white',
                            transition: 'background-color 0.15s ease',
                        }}
                    >
                        <IconRefresh size={16} />
                        <Text size="sm" fw={500}>Refresh</Text>
                    </Box>
                </Group>
            </Box>

            {/* Disk List */}
            <Text size="lg" fw={600} mb="md">Physical Disks</Text>

            {error && (
                <Text c="red" mb="md">{error}</Text>
            )}

            {!loading && !error && disks.length === 0 && (
                <Text c="dimmed">No disks found on this system.</Text>
            )}

            {!loading && !error && disks.length > 0 && (
                <Box>
                    {disks.map((disk) => (
                        <DiskCard key={disk.name} disk={disk} />
                    ))}
                </Box>
            )}
        </Box>
    );
}
