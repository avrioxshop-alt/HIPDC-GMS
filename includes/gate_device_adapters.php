<?php

interface QrScannerAdapter
{
    public function readToken(array $payload): string;
}

interface AnprAdapter
{
    public function readPlate(array $payload): string;
}

interface CameraAdapter
{
    public function validateCaptureMetadata(array $payload): array;
}

interface GateControllerAdapter
{
    public function requestOpen(array $context): array;
}

final class DisabledGateController implements GateControllerAdapter
{
    public function requestOpen(array $context): array
    {
        return [
            'command_sent' => false,
            'status' => 'disabled',
            'message' => 'No configured physical gate controller is available.',
        ];
    }
}
