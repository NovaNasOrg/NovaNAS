import { useState } from 'react';
import { Box, TextInput, Button, Stack, Title, Text, Alert, Paper, Center } from '@mantine/core';
import { IconLock, IconCheck } from '@tabler/icons-react';
import { useForm } from '@inertiajs/react';

export default function SetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        password: '',
        password_confirmation: '',
    });

    const [success, setSuccess] = useState(false);
    const [customError, setCustomError] = useState(null);

    const handleSubmit = (e) => {
        e.preventDefault();
        setCustomError(null);

        if (data.password !== data.password_confirmation) {
            setCustomError('Passwords do not match');
            return;
        }

        if (data.password.length < 8) {
            setCustomError('Password must be at least 8 characters');
            return;
        }

        post('/set-password', {
            onSuccess: () => {
                setSuccess(true);
            },
            onError: (errors) => {
                setCustomError(errors.token || errors.password || 'Failed to set password');
            },
        });
    };

    if (success) {
        return (
            <Center style={{ height: '100vh', backgroundColor: '#1a1b1e' }}>
                <Paper
                    p="xl"
                    radius="md"
                    style={{ width: '100%', maxWidth: '400px', backgroundColor: '#25262b' }}
                >
                    <Stack align="center" gap="md">
                        <Box
                            style={{
                                width: '64px',
                                height: '64px',
                                borderRadius: '50%',
                                backgroundColor: 'var(--mantine-color-green-6)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}
                        >
                            <IconCheck size={32} color="white" />
                        </Box>
                        <Title order={3} c="white">Password Set!</Title>
                        <Text c="dimmed" ta="center">
                            Your password has been set successfully. You can now log in to NovaNAS.
                        </Text>
                        <Button
                            component="a"
                            href="/login"
                            fullWidth
                            mt="md"
                        >
                            Go to Login
                        </Button>
                    </Stack>
                </Paper>
            </Center>
        );
    }

    return (
        <Center style={{ height: '100vh', backgroundColor: '#1a1b1e' }}>
            <Paper
                p="xl"
                radius="md"
                style={{ width: '100%', maxWidth: '400px', backgroundColor: '#25262b' }}
            >
                <Stack component="form" onSubmit={handleSubmit} gap="md">
                    <div>
                        <Title order={3} c="white">Set Your Password</Title>
                        <Text size="sm" c="dimmed" mt="xs">
                            Create a password for your account
                        </Text>
                    </div>

                    {email && (
                        <Text size="sm" c="dimmed">
                            Invitation for: <Text span fw={500} c="white">{email}</Text>
                        </Text>
                    )}

                    {(customError || errors.token) && (
                        <Alert color="red" variant="light">
                            {customError || errors.token}
                        </Alert>
                    )}

                    <TextInput
                        label="Password"
                        placeholder="Enter your password"
                        type="password"
                        leftSection={<IconLock size={16} />}
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        required
                    />

                    <TextInput
                        label="Confirm Password"
                        placeholder="Confirm your password"
                        type="password"
                        leftSection={<IconLock size={16} />}
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        error={errors.password_confirmation}
                        required
                    />

                    <Button
                        type="submit"
                        fullWidth
                        loading={processing}
                        mt="md"
                    >
                        Set Password
                    </Button>
                </Stack>
            </Paper>
        </Center>
    );
}
